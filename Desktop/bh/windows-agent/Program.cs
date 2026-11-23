using System.Collections.Concurrent;
using System.Diagnostics.Eventing.Reader;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Xml.Linq;
using System.Security.Cryptography;
using System.Net.Http.Json;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.DependencyInjection;

var builder = Host.CreateApplicationBuilder(args);
var configPath = Environment.GetEnvironmentVariable("BRAVADO_AGENT_CONFIG_PATH") ?? Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "Bravado", "AttendanceAgent", "agent.json");
var agentSettings = JsonSerializer.Deserialize<AgentConfiguration>(File.ReadAllText(configPath), new JsonSerializerOptions(JsonSerializerDefaults.Web))
    ?? throw new InvalidOperationException("The attendance agent configuration file is invalid.");
if (string.IsNullOrWhiteSpace(agentSettings.HrmsUrl) || string.IsNullOrWhiteSpace(agentSettings.ComputerCode) || string.IsNullOrWhiteSpace(agentSettings.TokenDpapi) || string.IsNullOrWhiteSpace(agentSettings.SpoolPath))
    throw new InvalidOperationException("The attendance agent configuration is missing required values.");
builder.Services.AddWindowsService(options => options.ServiceName = "Bravado Attendance Agent");
builder.Services.AddHttpClient("hrms", client =>
{
    var baseUrl = agentSettings.HrmsUrl;
    if (!Uri.TryCreate(baseUrl, UriKind.Absolute, out var uri) || uri.Scheme != Uri.UriSchemeHttps) throw new InvalidOperationException("BRAVADO_HRMS_URL must use HTTPS.");
    client.BaseAddress = new Uri(uri.AbsoluteUri.TrimEnd('/') + "/");
    client.Timeout = TimeSpan.FromSeconds(20);
    client.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
});
builder.Services.AddSingleton(agentSettings);
builder.Services.AddHostedService<AttendanceWorker>();
await builder.Build().RunAsync();

sealed class AttendanceWorker : BackgroundService
{
    private const string Query = "*[System[(EventID=4624 or EventID=4634 or EventID=4647)]]";
    private readonly IHttpClientFactory _clients;
    private readonly ILogger<AttendanceWorker> _logger;
    private readonly string _code;
    private readonly string _token;
    private readonly string _spool;
    private readonly string _sessions;
    private readonly object _fileLock = new();
    private readonly ConcurrentDictionary<string, Session> _interactiveSessions = new(StringComparer.OrdinalIgnoreCase);
    private EventLogWatcher? _watcher;
    private DateTime _lastHealth = DateTime.MinValue;

    public AttendanceWorker(IHttpClientFactory clients, ILogger<AttendanceWorker> logger, AgentConfiguration settings)
    {
        _clients = clients; _logger = logger; _spool = settings.SpoolPath; _sessions = Path.Combine(Path.GetDirectoryName(_spool)!, "sessions.json");
        _code = settings.ComputerCode;
        _token = Encoding.UTF8.GetString(ProtectedData.Unprotect(Convert.FromBase64String(settings.TokenDpapi), null, DataProtectionScope.LocalMachine));
        Directory.CreateDirectory(Path.GetDirectoryName(_spool)!);
        if (File.Exists(_sessions))
            foreach (var pair in JsonSerializer.Deserialize<Dictionary<string, Session>>(Unprotect(File.ReadAllText(_sessions))) ?? []) _interactiveSessions[pair.Key] = pair.Value;
    }

    protected override async Task ExecuteAsync(CancellationToken stoppingToken)
    {
        var query = new EventLogQuery("Security", PathType.LogName, Query) { ReverseDirection = false };
        _watcher = new EventLogWatcher(query, null, readExistingEvents: false);
        _watcher.EventRecordWritten += OnEventRecordWritten;
        _watcher.Enabled = true;
        _logger.LogInformation("Agent started for {ComputerCode}; monitoring interactive Windows sign-in and sign-out events.", _code);
        using var timer = new PeriodicTimer(TimeSpan.FromSeconds(15));
        while (await timer.WaitForNextTickAsync(stoppingToken))
        {
            await FlushQueue(stoppingToken);
            if (DateTime.UtcNow - _lastHealth > TimeSpan.FromMinutes(5)) await ReportHealth(stoppingToken);
        }
    }

    private void OnEventRecordWritten(object? sender, EventRecordWrittenEventArgs args)
    {
        if (args.EventException is not null) { _logger.LogError(args.EventException, "Unable to read a Windows Security event."); return; }
        using var record = args.EventRecord;
        if (record is null) return;
        try
        {
            var xml = XDocument.Parse(record.ToXml());
            var fields = xml.Descendants().Where(e => e.Name.LocalName == "Data").Where(e => e.Attribute("Name") is not null)
                .ToDictionary(e => e.Attribute("Name")!.Value, e => e.Value, StringComparer.OrdinalIgnoreCase);
            fields.TryGetValue("LogonType", out var logonTypeText);
            int? logonType = int.TryParse(logonTypeText, out var parsedType) ? parsedType : null;
            var logonId = Field(fields, "TargetLogonId", "SubjectLogonId");
            Session session;
            if (record.Id == 4624)
            {
                if (logonType is not (2 or 7 or 10 or 11)) return;
                var account = Field(fields, "TargetUserName"); var sid = Field(fields, "TargetUserSid");
                if (!IsEmployeeIdentity(account, sid) || string.IsNullOrWhiteSpace(logonId)) return;
                session = new Session(Field(fields, "TargetDomainName"), account, sid, logonType.Value);
                _interactiveSessions[logonId] = session;
                SaveSessions();
            }
            else
            {
                if (string.IsNullOrWhiteSpace(logonId) || !_interactiveSessions.TryGetValue(logonId, out session!)) return;
                if (logonType is not null && logonType is not (2 or 7 or 10 or 11)) return;
                _interactiveSessions.TryRemove(logonId, out _);
                SaveSessions();
            }

            var eventTime = record.TimeCreated?.ToUniversalTime() ?? DateTime.UtcNow;
            var item = new AgentEvent($"{_code}:{Environment.MachineName}:{record.RecordId}", record.Id, eventTime, $"{session.Domain}\\{session.Account}", session.Sid, session.LogonType, new { computer = Environment.MachineName, channel = "Security", record_id = record.RecordId, logon_id = logonId, event_xml = record.ToXml() });
            lock (_fileLock) File.AppendAllText(_spool, Protect(JsonSerializer.Serialize(item)) + Environment.NewLine, Encoding.UTF8);
        }
        catch (Exception ex) { _logger.LogError(ex, "Failed to persist Windows event record {RecordId}; it was not acknowledged.", record.RecordId); }
    }

    private async Task FlushQueue(CancellationToken ct)
    {
        List<string> lines;
        lock (_fileLock) lines = File.Exists(_spool) ? File.ReadAllLines(_spool).Where(line => !string.IsNullOrWhiteSpace(line)).ToList() : [];
        if (lines.Count == 0) return;
        var client = AuthenticatedClient(); var sent = 0;
        foreach (var batch in lines.Chunk(100))
        {
            try
            {
                var events = batch.Select(line => JsonSerializer.Deserialize<JsonElement>(Unprotect(line))).ToArray();
                using var response = await client.PostAsJsonAsync("api/attendance/computers/events", new { events }, ct);
                if (!response.IsSuccessStatusCode) { _logger.LogWarning("HRMS returned {Status}; keeping queued events for retry.", response.StatusCode); break; }
                sent += batch.Length;
            }
            catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException) { _logger.LogWarning(ex, "HRMS is unavailable; keeping events in the local queue."); break; }
        }
        if (sent == 0) return;
        lock (_fileLock)
        {
            var current = File.Exists(_spool) ? File.ReadAllLines(_spool).ToList() : [];
            var temp = _spool + ".tmp";
            File.WriteAllLines(temp, current.Skip(Math.Min(sent, current.Count)), Encoding.UTF8);
            File.Move(temp, _spool, overwrite: true);
        }
        _logger.LogInformation("Sent {Count} Windows attendance events.", sent);
    }

    private async Task ReportHealth(CancellationToken ct)
    {
        try
        {
            var queueCount = 0; lock (_fileLock) if (File.Exists(_spool)) queueCount = File.ReadLines(_spool).Count();
            using var response = await AuthenticatedClient().PostAsJsonAsync("api/attendance/computers/health", new { agent_version = "0.1.0", status = "healthy", details = $"Queued events: {queueCount}", queued_events = queueCount }, ct);
            response.EnsureSuccessStatusCode(); _lastHealth = DateTime.UtcNow;
        }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException) { _logger.LogWarning(ex, "Agent health report failed; will retry."); }
    }

    private HttpClient AuthenticatedClient()
    {
        var client = _clients.CreateClient("hrms");
        client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", _token);
        client.DefaultRequestHeaders.Add("X-Device-Code", _code);
        return client;
    }

    private void SaveSessions()
    {
        var temp = _sessions + ".tmp";
        File.WriteAllText(temp, Protect(JsonSerializer.Serialize(_interactiveSessions.ToDictionary(pair => pair.Key, pair => pair.Value))));
        File.Move(temp, _sessions, overwrite: true);
    }

    private static bool IsEmployeeIdentity(string account, string sid) => !string.IsNullOrWhiteSpace(account) && !account.EndsWith('$') &&
        !account.Equals("SYSTEM", StringComparison.OrdinalIgnoreCase) && !account.Equals("ANONYMOUS LOGON", StringComparison.OrdinalIgnoreCase) &&
        !string.IsNullOrWhiteSpace(sid) && !sid.EndsWith("-500", StringComparison.Ordinal) && !sid.EndsWith("-501", StringComparison.Ordinal);
    private static string Protect(string value) => Convert.ToBase64String(ProtectedData.Protect(Encoding.UTF8.GetBytes(value), null, DataProtectionScope.LocalMachine));
    private static string Unprotect(string value) => Encoding.UTF8.GetString(ProtectedData.Unprotect(Convert.FromBase64String(value), null, DataProtectionScope.LocalMachine));
    private static string Field(Dictionary<string, string> fields, params string[] names) => names.Select(name => fields.GetValueOrDefault(name)).FirstOrDefault(value => !string.IsNullOrWhiteSpace(value)) ?? string.Empty;
    public override void Dispose() { _watcher?.Dispose(); base.Dispose(); }
    private sealed record Session(string Domain, string Account, string Sid, int LogonType);
    private sealed record AgentEvent(string event_identifier, int event_id, DateTime event_timestamp, string windows_account, string windows_sid, int logon_type, object payload);
}

sealed record AgentConfiguration(string HrmsUrl, string ComputerCode, string TokenDpapi, string SpoolPath);

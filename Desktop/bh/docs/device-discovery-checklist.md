# Bravado attendance device discovery checklist

Complete one copy per physical ZKTeco machine before selecting an adapter.

- Branch and internal device code.
- Exact model name and model number from the label.
- Serial number and firmware version from the device information screen.
- Network interface and supported communication methods.
- IP address and port only when the manufacturer documents that method for this model and firmware.
- ADMS/Push support and vendor setup instructions, if present.
- SDK/API name, version, license terms, and model/firmware compatibility evidence.
- Event retrieval capability and documented event fields.
- USB/export format and sample export file.
- Device timezone and clock synchronization settings.
- Network route, firewall rules, and secure connectivity approach for Dar es Salaam/Dodoma.
- Test enrollment and event retrieval with one authorized test employee.
- Test retry after a controlled network interruption; confirm no lost or duplicated events.

Do not store passwords, private keys, or reusable device secrets in this checklist. Record which vendor document/version supports the chosen method. A dashboard state becomes `connected` only after the verified device sends an authenticated event batch.

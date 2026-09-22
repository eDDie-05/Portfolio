# Edson Kamugisha Edwin: portfolio website

A multi-page static portfolio built with plain HTML, CSS and JavaScript (Version 1). No build step and no frameworks. Dark mode is the default, with a light mode toggle.

## Pages

| File | What it is |
| --- | --- |
| `index.html` | Home: hero with animated network background, status cards, technology marquee, featured projects, experience, call to action |
| `about.html` | About, skills, experience, education, certificates |
| `projects.html` | All projects with filter buttons |
| `project-endpoint.html`, `project-tax.html`, `project-land.html`, `project-sms.html` | One page per project: problem, solution, features, facts, previous/next buttons |
| `contact.html` | Contact cards, copy-email button, contact form |

## Folder structure

```
portfolio/
├── index.html, about.html, projects.html, contact.html
├── project-endpoint.html, project-tax.html, project-land.html, project-sms.html
├── css/style.css        colours (design tokens at the top), layout, animations, dark and light themes
├── js/main.js           theme toggle, mobile menu, page transitions, cursor effects, network canvas, filters, form
├── images/              SVG illustrations, favicon and the circuit background pattern
│   └── projects/        put real project screenshots here
├── documents/           put cv.pdf here
└── README.md
```

## Effects included

- Animated network background in the hero that reacts to the mouse
- Typing effect, floating code window with lines that appear one by one, scrolling technology marquee
- Scroll progress bar, reveal-on-scroll animations, page fade between pages
- Custom cursor ring (mouse devices only), card spotlight and tilt on hover, magnetic buttons, shine sweep on primary buttons
- Button navigation: pill menu on desktop, full-width button menu on phones, filter buttons, previous/next project buttons, back-to-top button

All motion switches off automatically for visitors who have "reduce motion" turned on.

## Before you publish: fill in these gaps

1. **CV.** Save your CV as `documents/cv.pdf`. The "Download CV" buttons point to it.
2. **LinkedIn.** The link currently opens a LinkedIn name search. Replace it with your profile URL (`https://www.linkedin.com/in/your-username`) in `contact.html` and in the footer of every page.
3. **Illustrations.** The pictures on the project pages are concept illustrations, not screenshots. When you have real screenshots, save them in `images/projects/` and swap the `<img src>` on the project card and detail page.
4. **Bravado Endpoint Security System.** Add the technologies you used, an architecture diagram and the GitHub link. A comment in `project-endpoint.html` marks the spot.
5. **Project GitHub links.** Each project page links to your GitHub profile. Change it to the repository once each one is public.
6. **Skills.** A filled dot means you use it in projects, an outlined dot means you are still learning it. Remove anything you cannot explain in an interview.
7. **Certificates.** When you earn one, add it in the Certificates section of `about.html` (a commented example is included).
8. **After you get a domain.** Add `<link rel="canonical" href="https://your-domain/">` and `<meta property="og:url" content="https://your-domain/">` to the `<head>` of each page.

## Change the colours

Everything is controlled by the variables at the top of `css/style.css`. `--accent` and `--accent-2` are the cyan and blue, `--bg` is the page background. Light mode has its own set under `:root[data-theme="light"]`.

## Run it on your computer

Open `index.html` in a browser, or from this folder run:

```
python -m http.server 8000
```

then visit http://localhost:8000.

## Publish it with GitHub Pages (free)

```
git init
git add .
git commit -m "Add portfolio website"
git branch -M main
git remote add origin https://github.com/eDDie-05/portfolio.git
git push -u origin main
```

Then on GitHub open the repository, go to **Settings > Pages**, choose **Deploy from a branch**, select `main` and `/ (root)`, and save. Your site appears at `https://eddie-05.github.io/portfolio/` after a minute or two. Vercel and Netlify also work: drag the folder in.

## Roadmap

- **Version 1 (this):** responsive multi-page site, dark/light mode, animations, projects, experience, education, contact, CV download.
- **Version 2:** blog, a real contact form with a Spring Boot API, PostgreSQL for projects, an admin dashboard with authentication.
- **Version 3:** visitor analytics, SEO improvements, project search, automated deployment, Docker, CI/CD, security headers, rate limiting, HTTPS.

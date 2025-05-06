# Local Development Guide

This document explains how to view, run, and test your basic PHP project locally.

---

## ✅ How to Run and View the Project Locally

### 1️⃣ Ensure PHP is Installed

Check if PHP is installed by running:

```bash
php -v
```

If PHP is not installed:

* Install PHP (available for Windows, Mac, and Linux).

### 2️⃣ Use PHP's Built-In Server

You don't need Apache or Nginx for local development — PHP provides a built-in server.

From your project root (where `/public/` is located), run:

```bash
php -S localhost:8000 -t public
```

**What this does:**

* `localhost:8000` → Starts a local server on port 8000.
* `-t public` → Uses `/public` as the web root (so `index.php` will be found properly).

### 3️⃣ Open Your Browser

Visit:

```
http://localhost:8000
```

You should see your homepage (`index.php`), with navigation links.

✅ You can now:

* View Matches
* View Referees
* Assign Referees

---

---

---

## ✅ Final Next Steps

| Step | Action                                       |
| ---- | -------------------------------------------- |
| 1    | Run `php provision.php`                      |
| 2    | Run `php seed.php`                           |
| 3    | Run `php -S localhost:8000 -t public`        |
| 4    | Open `http://localhost:8000` in your browser |
| 5    | Test and enjoy 🚀                            |

---

That's it — you're ready to go!
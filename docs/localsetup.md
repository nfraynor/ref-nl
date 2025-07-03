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

## ✅ How to Run with Docker

### 1️⃣ Build the Docker Image

From the root of the project, run:

```bash
docker build -t ref-nl-app .
```

### 2️⃣ Run the Docker Container

Run the following command to start the container:

```bash
docker run -d -p 8080:80 --name ref-nl-container ref-nl-app
```

**What this does:**

* `docker run -d`: Runs the container in detached mode.
* `-p 8080:80`: Maps port 8080 on your host machine to port 80 in the container (Apache's default port).
* `--name ref-nl-container`: Assigns a name to the container for easier management.
* `ref-nl-app`: The name of the image to use.

### 3️⃣ Open Your Browser

Visit:

```
http://localhost:8080
```

You should see your homepage. The application and database are running inside the Docker container.

### Database Credentials

The application will connect to the MySQL database running inside the container using the following default credentials (defined in `php/config/database.php` and `entrypoint.sh`):

*   **Host:** `localhost` (within the container)
*   **Database Name:** `refnl`
*   **Username:** `refnl_user`
*   **Password:** `password`

These can be overridden by setting the following environment variables when running the `docker run` command:
*   `DB_HOST`
*   `DB_DATABASE`
*   `DB_USERNAME`
*   `DB_PASSWORD`

For example:
```bash
docker run -d -p 8080:80 \
  -e DB_DATABASE=my_refnl_db \
  -e DB_USERNAME=my_user \
  -e DB_PASSWORD=my_secret_password \
  --name ref-nl-container ref-nl-app
```

### ✅ (Legacy) Final Next Steps - Manual PHP Server

If you are not using Docker, follow these steps:

| Step | Action                                       |
| ---- | -------------------------------------------- |
| 1    | Run `php provision.php` (ensure MySQL is running and configured as per `php/config/database.php` - you might need to update credentials from `root` with no password) |
| 2    | Run `php seed.php`                           |
| 3    | Run `php -S localhost:8000 -t public`        |
| 4    | Open `http://localhost:8000` in your browser |
| 5    | Test and enjoy 🚀                            |

---

That's it — you're ready to go!
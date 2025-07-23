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

## ✅ How to Run with Docker (Single Container with Data Persistence)

This method uses a single Dockerfile to build an image containing PHP, Apache, and MySQL. MySQL data is persisted using a Docker volume.

### 1️⃣ Build the Docker Image

From the root of the project, run:

```bash
docker build -t ref-nl-app .
```

### 2️⃣ Run the Docker Container with Data Persistence

To run the container and persist MySQL data, use the following command. This command creates a named volume `refnl_mysql_data` (or uses an existing one) and mounts it to `/var/lib/mysql` inside the container. It also maps port `3306` on your host to port `3306` in the container for external MySQL access.

```bash
docker run -d -p 8080:80 -p 3306:3306 -v refnl_mysql_data:/var/lib/mysql --name ref-nl-container ref-nl-app
```

**What this does:**

*   `docker run -d`: Runs the container in detached mode (in the background).
*   `-p 8080:80`: Maps port 8080 on your host machine to port 80 in the container (Apache's default port for the web application).
*   `-p 3306:3306`: Maps port 3306 on your host machine to port 3306 in the container (MariaDB/MySQL's default port). This allows you to connect to the MariaDB/MySQL server from your host using a tool like MySQL Workbench or `mysql` CLI.
*   `-v refnl_mysql_data:/var/lib/mysql`: **Using a Named Volume (Recommended for simplicity and performance on Mac/Windows).** Mounts a Docker-managed named volume called `refnl_mysql_data` to the `/var/lib/mysql` directory in the container. This is where MariaDB/MySQL stores its data. If the volume doesn't exist, Docker creates it. The `entrypoint.sh` script inside the container attempts to handle permissions for this directory.
*   **Alternatively, Using a Host Bind Mount:** You can use a directory from your host machine instead of a named volume. Replace `refnl_mysql_data` with the absolute path to your host directory:
    ```bash
    # Example using a host bind mount:
    # mkdir -p ./data/mysql_on_host # Create the directory on your host first
    # docker run -d -p 8080:80 -p 3306:3306 -v $(pwd)/data/mysql_on_host:/var/lib/mysql --name ref-nl-container ref-nl-app
    ```
    *   **Permissions for Host Bind Mounts (especially on Linux):** The `entrypoint.sh` script now includes `chown mysql:mysql` and `chmod 700` for `/var/lib/mysql` inside the container. This should help with many permission issues. However, if you encounter persistent permission errors with bind mounts on Linux, you might still need to ensure the host directory's ownership or SELinux/AppArmor contexts are compatible with the `mysql` user (UID 999) inside the container. For instance, `sudo chown -R 999:999 ./data/mysql_on_host` on your Linux host *before* running the container might be necessary in some cases.
*   `--name ref-nl-container`: Assigns a name to the container for easier management.
*   `ref-nl-app`: The name of the image to use.

### 3️⃣ Open Your Browser

Visit:

```
http://localhost:8080
```

You should see your homepage. The application and its database (with persisted data) are running inside the Docker container.

### Database Credentials

The application connects to the MariaDB/MySQL database running inside the same container using the following default credentials (defined in `php/config/database.php` and `entrypoint.sh`):

*   **Host (for application inside container):** `localhost`
*   **Host (for external tools on host machine):** `127.0.0.1` (or `localhost`)
*   **Port (for external tools on host machine):** `3306` (due to the `-p 3306:3306` mapping)
*   **Database Name:** `refnl`
*   **Database Server:** MySQL
*   **Username:** `root`
*   **Password:** `password` (this is the default set by `entrypoint.sh` for the `root` user and used in `php/config/database.php`)

These can be overridden by setting environment variables when running the `docker run` command (e.g., `-e DB_DATABASE=my_db -e DB_USERNAME=custom_user -e DB_PASSWORD=custom_pass`). If you change `DB_USERNAME` and `DB_PASSWORD` environment variables, you would also need to adjust the `entrypoint.sh` script to use these variables when setting up the MariaDB/MySQL user and permissions, or handle user creation manually. Currently, `entrypoint.sh` specifically configures the `root` user with the password 'password'.

### Managing the Container and Volume:

*   **Stop the container:** `docker stop ref-nl-container`
*   **Start the container:** `docker start ref-nl-container`
*   **Remove the container (data in `refnl_mysql_data` volume remains):** `docker rm ref-nl-container`
*   **View Docker volumes:** `docker volume ls`
*   **Remove the named volume (deletes persisted MySQL data):** `docker volume rm refnl_mysql_data` (Ensure the container using it is stopped and removed first).

## ✅ (Legacy) Final Next Steps - Manual PHP Server

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
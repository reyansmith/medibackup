# Mannath Medicals

A web-based pharmacy management system built with PHP and MySQL for handling core day-to-day operations such as inventory, purchases, vendors, billing, and reporting.

## Features

- Role-based access for administrators and employees
- Inventory management
- Vendor and purchase management
- Billing workflow for employees
- Dashboard and reporting modules
- Session-based authentication

## Tech Stack

- PHP
- MySQL / MariaDB
- HTML, CSS, JavaScript
- Chart.js

## Project Structure

```text
medibackup/
├── admin/       # Admin modules
├── assets/      # Styles and images
├── auth/        # Login and logout
├── config/      # Database configuration
├── employee/    # Employee modules
├── includes/    # Shared layout files
├── index.php
└── README.md
```

## Getting Started

1. Clone or copy the project into your XAMPP `htdocs` directory:

   ```text
   C:\xampp\htdocs\reyan\medibackup
   ```

2. Start `Apache` and `MySQL` from XAMPP.

3. Create the database:

   ```sql
   CREATE DATABASE medivault_db;
   ```

4. Update database credentials in `config/database.php` if required.

5. Open the project in your browser:

   ```text
   http://localhost/reyan/medibackup
   ```

## Application Modules

### Admin

- Dashboard
- Inventory
- Purchases
- Vendors
- Reports
- Employees

### Employee

- Dashboard
- Billing
- Reports

## Notes

- The application entry point is `index.php`, which redirects to the login page.
- Authentication is handled through `auth/login.php`.
- The project expects a MySQL database named `medivault_db`.

## License

This project is intended for internal or private use unless a separate license is added.

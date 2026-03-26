# MediVault 2nd - Medical Inventory & Billing System

A web-based inventory and billing system for pharmacies and medical stores built with PHP and MySQL.

## Quick Start

### Setup
1. Extract to `C:\xampp\htdocs\medivault2nd`
2. Create database: `CREATE DATABASE medivault_db;`
3. Update `config/database.php` with your MySQL credentials
4. Start Apache and MySQL in XAMPP
5. Open `http://localhost/medivault2nd`

### Default Login
- **Admin**: admin / password
- **Employee**: employee / password

## Features

- **Stock Management**: Track inventory with batch numbers and expiry dates
- **Billing**: Create invoices and generate bills
- **Purchase Orders**: Manage vendor purchases
- **Employee Management**: Add and manage employees
- **Reports**: Sales, inventory, and employee performance reports
- **Dashboard**: Real-time metrics and alerts

## User Roles

**Admin**
- Inventory management
- Purchase orders
- Vendor management
- Employee management
- All reports

**Employee**
- Create invoices
- View personal sales
- Generate reports

## Project Structure

```
medivault2nd/
├── admin/          # Admin features
├── employee/       # Employee features
├── auth/           # Login/logout
├── config/         # Database config
├── includes/       # Page templates
└── assets/css/     # Styling
```

## Tech Stack

- PHP 7.2+
- MySQL / MariaDB
- HTML5, CSS3, JavaScript
- Chart.js

## Database Tables

- **employee**: User accounts and roles
- **product**: Medicine information
- **stock**: Inventory tracking
- **bill**: Customer invoices
- **purchase**: Vendor purchases
- **vendor**: Supplier information

## Requirements

- XAMPP or similar (Apache + PHP + MySQL)
- Modern web browser
- 10GB disk space (recommended)



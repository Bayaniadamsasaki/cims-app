# BACKEND_CONTEXT.md

# Campus Infrastructure Monitoring System (CIMS)

## Overview

Project ini merupakan aplikasi **Campus Infrastructure Monitoring System (CIMS)** yang dibangun menggunakan **Laravel 12 + React**.

Frontend dan Backend berada dalam **satu project Laravel**.

Fokus pengembangan saat ini adalah **Backend Foundation**.

---

# Tech Stack

## Backend

- Laravel 12
- PHP 8.3+
- MySQL 8+
- Laravel Sanctum
- Spatie Laravel Permission

## Frontend

- React
- Inertia.js
- Tailwind CSS
- Vite

---

# Architecture

Menggunakan **Repository Pattern**.

Flow aplikasi:

Request

↓

Controller

↓

Repository

↓

Model

↓

Resource

↓

JSON Response

---

# Folder Responsibilities

## Controllers

- Endpoint API
- Validasi Request
- Memanggil Repository
- Return Resource

Tidak boleh terdapat query database yang kompleks.

---

## Requests

Semua validasi request harus menggunakan Form Request.

---

## Resources

Semua response API menggunakan Laravel API Resource.

---

## Interfaces

Berisi seluruh Interface Repository.

Contoh:

- UserRepositoryInterface
- DeviceRepositoryInterface

---

## Repositories

Berisi implementasi Repository.

Repository bertugas:

- CRUD
- Query Database
- Filtering
- Searching
- Pagination

---

## Models

Berisi seluruh Eloquent Model.

---

## Helpers

Berisi helper global.

---

## Traits

Berisi reusable code.

---

# Database Structure

```text
database/

├── factories/

├── migrations/

└── seeders/
```

### factories

Berisi Laravel Model Factory.

Digunakan untuk:

- Seeder
- Testing
- Dummy Data

---

### migrations

Berisi seluruh migration database.

---

### seeders

Berisi initial/master data.

---

# Composer Packages

Authentication

- laravel/sanctum

Role & Permission

- spatie/laravel-permission

Activity Log

- spatie/laravel-activitylog

Swagger

- darkaonline/l5-swagger

Excel

- maatwebsite/excel

PDF

- barryvdh/laravel-dompdf

Image

- intervention/image

---

# Coding Standard

- Gunakan Repository Pattern.
- Gunakan Interface untuk setiap Repository.
- Gunakan Form Request.
- Gunakan API Resource.
- Hindari query langsung di Controller.
- Gunakan Dependency Injection.
- Gunakan Route Model Binding.
- Gunakan Pagination untuk List API.
- Gunakan eager loading jika diperlukan.

---

# Current Sprint

Sprint 1

- Authentication
- Role Permission
- User Management
- Master Data
- Inventory Foundation

Belum mengembangkan Monitoring Engine.

---

# AI Instructions

Saat menghasilkan kode:

- Ikuti struktur folder project.
- Jangan membuat business logic yang kompleks di Controller.
- Semua validasi menggunakan Form Request.
- Semua response menggunakan API Resource.
- Semua akses database melalui Repository.
- Gunakan Interface untuk setiap Repository.
- Ikuti Laravel Best Practice.
- Hasilkan kode yang clean, scalable, dan production-ready.

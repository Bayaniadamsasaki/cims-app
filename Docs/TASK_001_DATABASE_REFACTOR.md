# TASK_001_DATABASE_REFACTOR.md

# Database Refactor & Excel Import Preparation

## Context

Project ini adalah **Campus Infrastructure Monitoring System (CIMS)**.

Saat ini telah tersedia file inventaris jaringan kampus dalam format Excel.

File tersebut akan dijadikan **initial data source** untuk sistem.

Namun struktur Excel tidak boleh langsung dijadikan struktur database.

Database harus dinormalisasi terlebih dahulu agar scalable dan mudah dikembangkan.

---

# Objective

Refactor struktur database agar mendukung:

* Inventory Device
* Multiple Interface per Device
* Monitoring
* Alert
* Maintenance
* Future Network Monitoring

---

# Current Problem

Satu perangkat (Router/Switch) memiliki banyak interface.

Contoh:

Router MikroTik

ether1

ether2

ether3

ether4

ether5

Jika seluruh interface disimpan pada tabel devices maka akan terjadi duplikasi data.

Karena itu interface harus dipisahkan menjadi tabel sendiri.

---

# Database Architecture

Gunakan relasi berikut.

Building

↓

Floor

↓

Room

↓

Device

↓

Device Interface

---

# Master Tables

Bangun tabel berikut.

buildings ✅

floors ✅

rooms ✅

racks ✅ (tambahan, tidak ada di spec awal)

vendors ✅

device_categories ✅

device_types ✅

operating_systems ✅

devices ✅

device_interfaces ✅

---

# Device Table

Tabel devices hanya menyimpan informasi utama perangkat.

Contoh field:

* id ✅
* hostname ✅
* device_name ✅ (sebagai `name`)
* vendor_id ✅
* category_id ✅ (sebagai `device_category_id`)
* type_id ✅ (sebagai `device_type_id`)
* operating_system_id ✅
* building_id ✅
* floor_id ✅
* room_id ✅
* model ✅
* serial_number ✅
* firmware ✅
* purchase_date ✅
* warranty_date ✅ (sebagai `warranty`)
* username ✅
* password (encrypted) ✅ (sebagai `password_encrypted`)
* notes ✅
* status ✅
* created_at ✅
* updated_at ✅

Field tambahan (tidak di spec, tetapi berguna):

* ip_address (management IP)
* mac_address (management MAC)
* rack_id
* image_path

Jangan menyimpan interface pada tabel ini.

---

# Device Interface Table

Setiap perangkat dapat memiliki banyak interface.

Contoh field:

* id ✅
* device_id ✅
* interface_name ✅
* ip_address ✅
* subnet ✅
* gateway ✅
* mac_address ✅
* interface_type ✅
* interface_status ✅
* speed ✅
* mtu ✅
* description ✅
* created_at ✅
* updated_at ✅

Relasi:

Device

hasMany

Device Interface ✅

---

# Future Monitoring Tables

Persiapkan tabel berikut.

device_status → Sudah ada sebagai `device_metrics` ✅

device_metrics ✅

device_interfaces_statistics → Belum diimplementasi (future)

alerts → Belum diimplementasi (future)

maintenance_logs → Sudah ada sebagai `maintenance_tickets` ✅

notifications → Belum diimplementasi (future)

Jangan implementasikan sekarang.

Cukup siapkan migration plan.

---

# Excel Import Strategy

Jangan import Excel secara langsung.

Bangun mekanisme import bertahap.

Flow:

1.

Import Building

↓

2.

Import Floor

↓

3.

Import Room

↓

4.

Import Device

↓

5.

Import Device Interface

Semua relasi harus otomatis dibuat.

---

# Excel Import Rules

Jika Building belum ada

↓

buat Building

Jika Floor belum ada

↓

buat Floor

Jika Room belum ada

↓

buat Room

Jika Vendor belum ada

↓

buat Vendor

Jika Device Category belum ada

↓

buat Device Category

Jika Device sudah ada berdasarkan hostname

↓

update data

Jika belum ada

↓

buat Device baru

Kemudian import seluruh Interface.

---

# Repository Rules

Seluruh proses import harus menggunakan Repository Pattern. ✅

Jangan menggunakan query database langsung pada Controller. ✅

---

# Migration Rules

Setiap tabel memiliki migration masing-masing. ✅

Gunakan Foreign Key. ✅

Gunakan Index pada kolom berikut:

hostname ✅

device_name ✅ (via `name` column)

ip_address ✅

mac_address ✅

serial_number ✅

status ✅

building_id ✅ (FK auto-index)

room_id ✅ (FK auto-index)

device_id ✅ (FK auto-index)

---

# Factory

Buat Factory untuk:

Building ✅

Floor ✅

Room ✅

Vendor ✅

Device Category ✅

Device ✅

Device Interface ✅

Tambahan:

OperatingSystem ✅

DeviceType ✅

---

# Seeder

Siapkan Seeder untuk:

Role ✅

Permission ✅

Admin User ✅

Device Category ✅

Vendor ✅

Operating System ✅

Device Type ✅

Device Interface ✅

---

# Deliverables

Claude harus menghasilkan:

✅ Migration (23 total — termasuk 4 baru)

✅ Model (15 total — termasuk 3 baru: OperatingSystem, DeviceType, DeviceInterface)

✅ Repository (11 total — termasuk 3 baru)

✅ Interface (11 total — termasuk 3 baru)

✅ Form Request (18 total — termasuk 6 baru)

✅ API Resource (11 total — termasuk 3 baru)

✅ Factory (10 total — termasuk 9 baru)

✅ Seeder (updated dengan OS, DeviceType, DeviceInterface data)

Tanpa membuat Monitoring Engine terlebih dahulu.

---

# Important

Project masih berada pada Sprint 1.

Fokus hanya pada:

Inventory Foundation ✅

Database Structure ✅

REST API Foundation ✅

Jangan membuat fitur monitoring, alert, topology, maupun dashboard terlebih dahulu.

Seluruh kode harus mengikuti Laravel Best Practice dan struktur project yang sudah disediakan. ✅

---

# Completion Date

Task selesai: 2026-07-08

Semua deliverables telah diimplementasi dan migration + seed berhasil dijalankan.

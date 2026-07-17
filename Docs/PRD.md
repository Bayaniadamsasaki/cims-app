# PRD.md

# Campus Infrastructure Monitoring System (CIMS)

Version: 1.0

Status: Draft

---

# Product Vision

Membangun platform terpusat untuk monitoring infrastruktur jaringan dan manajemen inventaris aset TI kampus agar proses operasional, monitoring, troubleshooting, dan maintenance menjadi lebih cepat, efisien, dan terdokumentasi.

---

# Background

Tim IT kampus masih melakukan monitoring perangkat secara manual dan inventaris aset menggunakan spreadsheet.

Akibatnya:

* Sulit mengetahui status perangkat secara real-time.
* Tidak ada notifikasi otomatis saat perangkat mengalami gangguan.
* Inventaris sulit diperbarui.
* Riwayat maintenance tidak terdokumentasi.
* Sulit mengetahui lokasi perangkat saat troubleshooting.

---

# Objectives

Sistem harus mampu:

* Monitoring perangkat jaringan.
* Mengelola inventaris aset TI.
* Menampilkan kondisi perangkat secara real-time.
* Mengirim notifikasi gangguan.
* Menyimpan histori monitoring.
* Menyimpan histori maintenance.
* Menyediakan laporan.

---

# User Roles

## Super Admin

Mengelola seluruh sistem.

## Network Administrator

Mengelola perangkat dan monitoring.

## Technician

Melakukan maintenance.

## Viewer

Melihat dashboard dan laporan.

---

# Main Modules

## Authentication

* Login
* Logout
* Profile
* Change Password

---

## User Management

* CRUD User
* Role
* Permission

---

## Master Data

* Building
* Floor
* Room
* Rack
* Vendor
* Device Category

---

## Inventory

Mengelola data perangkat.

Informasi perangkat meliputi:

* Device Name
* Hostname
* IP Address
* MAC Address
* Vendor
* Model
* Serial Number
* Firmware
* Purchase Date
* Warranty
* Building
* Floor
* Room
* Rack
* Status
* Notes

---

## Monitoring

Monitoring otomatis:

* Online / Offline
* Ping
* Latency
* Packet Loss
* CPU
* Memory
* Storage
* Temperature
* Uptime
* Interface Status
* Bandwidth

---

## Alert

Kondisi:

* Device Offline
* CPU High
* RAM High
* Storage Full
* Interface Down
* Temperature High

Media:

* Telegram
* Email
* Webhook

---

## Maintenance

* Maintenance Schedule
* Maintenance History
* Attachment
* Technician
* Notes

---

## Reporting

* Inventory Report
* Monitoring Report
* Availability Report
* Alert Report
* Maintenance Report

Format:

* PDF
* Excel

---

# Development Roadmap

## Sprint 1

Backend Foundation

* Authentication
* Role & Permission
* User
* Master Data

## Sprint 2

Inventory

* CRUD Device
* Upload Image
* Import Excel
* Export Excel

## Sprint 3

Monitoring Engine

* Ping
* SNMP
* Device Metrics
* Scheduler

## Sprint 4

Alert

* Telegram
* Email
* Webhook

## Sprint 5

Maintenance

* Ticket
* Schedule
* History

## Sprint 6

Reporting

* PDF
* Excel

---

# Future Development

* Auto Discovery
* Interactive Network Topology
* SSH Remote
* Configuration Backup
* AI Network Assistant
* Predictive Maintenance

---

# Success Criteria

Project dianggap selesai apabila:

* Authentication berjalan.
* Role & Permission selesai.
* Master Data selesai.
* Inventory selesai.
* Monitoring berjalan otomatis.
* Alert terkirim.
* Reporting dapat dihasilkan.
* Seluruh modul terdokumentasi dengan baik.

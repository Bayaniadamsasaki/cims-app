# UI_GUIDELINE.md

# Campus Infrastructure Monitoring System (CIMS)

## UI & Design System Context

Seluruh halaman aplikasi WAJIB mengikuti design system yang konsisten.

Aplikasi ini merupakan Enterprise Dashboard untuk monitoring infrastruktur jaringan.

Jangan membuat style baru yang berbeda dari guideline ini.

---

# Design Style

Design harus memiliki karakteristik berikut:

* Premium
* Modern
* Enterprise
* Clean
* Dark Mode First
* High Contrast
* Futuristic
* Minimal
* Dashboard Focused

Inspirasi:

* Linear
* Raycast
* Vercel Dashboard
* Supabase Dashboard
* GitHub Dark
* Modern SaaS Dashboard

Hindari style seperti:

* AdminLTE
* Bootstrap Admin
* Metronic
* Tabler Default
* Dashboard jadul

---

# Theme

Dark Mode menjadi default.

Background menggunakan warna gelap.

Gunakan aksen hijau neon sebagai primary color.

---

# Color Palette

Primary Background

#0B0F0D

Secondary Background

#111513

Card Background

#161A18

Elevated Card

#1C201E

Primary

#22C55E

Primary Hover

#16A34A

Primary Light

#86EFAC

Border

rgba(255,255,255,0.06)

Text Primary

#FFFFFF

Text Secondary

#A1A1AA

Text Muted

#71717A

Success

#22C55E

Warning

#FACC15

Danger

#EF4444

Info

#3B82F6

Purple

#A855F7

---

# Layout

Gunakan layout berikut.

Sidebar kiri

*

Top Navigation

*

Main Content

*

Optional Right Sidebar

Jangan menggunakan layout fullscreen tanpa sidebar.

---

# Sidebar

Sidebar harus:

* Fixed
* Dark
* Width sekitar 260-280px

Menu aktif menggunakan:

* Background hijau neon
* Rounded
* Icon putih
* Text hitam atau gelap

Menu tidak aktif:

* Abu-abu
* Hover hijau transparan

---

# Navbar

Navbar:

* Tinggi sekitar 64px
* Transparent atau dark
* Search bar
* Notification
* Profile
* Breadcrumb

---

# Cards

Semua informasi menggunakan Card.

Card memiliki:

* Rounded XL
* Border tipis
* Background lebih terang dari body
* Shadow sangat lembut

Card jangan menggunakan gradient berlebihan.

---

# Charts

Gunakan chart modern.

Prioritas:

* Area Chart
* Line Chart
* Donut Chart
* Bar Chart

Chart menggunakan warna:

Hijau

Hijau muda

Kuning

Merah

Biru

Jangan memakai warna random.

---

# Tables

Gunakan Data Table modern.

Style:

* Rounded
* Dark
* Hover
* Sticky Header
* Search
* Pagination

---

# Forms

Input:

* Rounded
* Background gelap
* Border tipis
* Focus hijau

Button:

Primary

Secondary

Outline

Ghost

Danger

Semua button rounded.

---

# Icons

Gunakan hanya:

Lucide React

Jangan mencampur Heroicons, Bootstrap Icons, FontAwesome.

---

# Typography

Gunakan font:

Geist

atau

Inter

Hierarchy:

H1

28px

Bold

H2

20px

SemiBold

H3

16px

SemiBold

Body

14px

Caption

12px

---

# Border Radius

Card

20px

Button

12px

Input

12px

Modal

20px

Badge

999px

---

# Shadow

Gunakan shadow halus.

Contoh:

Small

0 2px 4px rgba(0,0,0,.2)

Medium

0 4px 12px rgba(0,0,0,.35)

Large

0 8px 24px rgba(0,0,0,.45)

Glow

Gunakan glow hijau hanya pada komponen penting.

Jangan berlebihan.

---

# Spacing

Gunakan sistem 8px.

4

8

12

16

24

32

40

48

64

Jangan menggunakan spacing random.

---

# Component Rules

Semua halaman menggunakan komponen yang sama.

Contoh:

Sidebar

TopNavbar

PageHeader

StatCard

Card

ChartCard

DataTable

SearchInput

Select

Badge

Button

Modal

Drawer

ConfirmDialog

Pagination

Loading

EmptyState

---

# Dashboard Rules

Dashboard harus menampilkan:

* Statistic Cards
* Monitoring Charts
* Recent Alerts
* Recent Activity
* Device Status
* Traffic Overview

Gunakan grid yang konsisten.

---

# Animation

Transition:

200ms

Ease:

ease-in-out

Hover:

Scale kecil

Glow ringan

Tidak menggunakan animasi berlebihan.

---

# Responsive

Desktop menjadi prioritas.

Tablet mendukung.

Mobile cukup usable.

---

# AI Instructions

Saat membuat halaman baru:

* Ikuti design system ini.
* Jangan mengubah layout utama.
* Gunakan warna yang sudah ditentukan.
* Gunakan typography yang sama.
* Gunakan spacing yang konsisten.
* Gunakan komponen yang sudah ada.
* Jangan membuat style baru tanpa instruksi.
* Semua halaman harus terlihat seperti berasal dari satu design system yang sama.
* Jika ragu, prioritaskan tampilan minimalis, modern, dan enterprise.

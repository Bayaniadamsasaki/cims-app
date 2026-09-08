# CIMS — DESIGN DIRECTION
## Enterprise Network Operations Dashboard
## Anti-Slop UI Contract

Gunakan dokumen ini sebagai arahan desain visual CIMS.

Referensi visual:
- Gunakan screenshot referensi yang diberikan user sebagai INSPIRASI DESIGN LANGUAGE.
- JANGAN menyalin branding, warna hijau, logo, teks, atau data dari screenshot.
- CIMS tetap menggunakan identitas Blue + White.
- Tujuan utama: membuat dashboard terasa seperti produk enterprise yang benar-benar dirancang manusia, bukan template AI-generated dashboard.

==================================================
1. DESIGN CHARACTER
==================================================

CIMS harus terasa:

- enterprise
- professional
- operational
- trustworthy
- clean
- airy
- information-dense tetapi tidak crowded
- modern tanpa menjadi trendy
- calm
- precise
- data-oriented

Visual reference direction:

"Premium enterprise SaaS dashboard"
+
"Network Operations Center"
+
"Editorial information hierarchy"

Hindari tampilan:

- AI dashboard template
- generic SaaS template
- excessive cards
- bento grid berlebihan
- glassmorphism
- gradient-heavy UI
- neon UI
- glowing UI
- excessive pills
- excessive rounded corners
- excessive shadows
- decorative blobs
- decorative grids
- fake statistics
- unnecessary illustrations
- emoji icons
- random colors
- excessive animations

==================================================
2. CORE VISUAL PRINCIPLE
==================================================

Jangan membuat setiap informasi menjadi CARD.

Gunakan hierarchy:

PAGE
 ├── Navigation
 ├── Header
 ├── Page title / context
 ├── Primary metrics
 ├── Main analytical content
 ├── Supporting operational content
 └── Secondary information

Card hanya digunakan ketika grouping informasi memang membantu
pemahaman.

Gunakan whitespace sebagai bagian dari desain.

Jangan mengisi setiap area kosong dengan card.

==================================================
3. COLOR SYSTEM
==================================================

CIMS BRAND:

Primary:
#2563EB

Primary Dark:
#1E3A8A

Primary Soft:
#EFF6FF

Page Background:
#F8FAFC

Surface:
#FFFFFF

Text Primary:
#0F172A

Text Secondary:
#475569

Text Muted:
#64748B

Border:
#E2E8F0

Border Soft:
#F1F5F9

Success:
#16A34A

Success Soft:
#F0FDF4

Warning:
#D97706

Warning Soft:
#FFFBEB

Danger:
#DC2626

Danger Soft:
#FEF2F2

Info:
#2563EB

IMPORTANT:

Blue adalah BRAND COLOR.

Green/amber/red hanya digunakan untuk SEMANTIC STATUS.

Jangan menggunakan emerald sebagai brand/accent utama.

Jangan menggunakan gradient sebagai primary visual treatment.

Jangan menggunakan blue-purple gradient.

Jangan menggunakan glow/neon.

==================================================
4. COLOR USAGE RATIO
==================================================

Gunakan prinsip:

70% neutral / white
20% slate / structural
10% blue / semantic accents

Blue harus terasa penting karena digunakan secara terbatas.

Jangan membuat seluruh dashboard berwarna biru.

Primary blue digunakan untuk:

- active navigation
- primary CTA
- important links
- selected states
- chart emphasis
- focus state
- important metrics

==================================================
5. RADIUS SYSTEM
==================================================

JANGAN menggunakan rounded-full sebagai default.

Gunakan radius berdasarkan hierarchy:

Page-level cards:
rounded-xl

Small cards / compact panels:
rounded-lg

Buttons:
rounded-lg

Inputs:
rounded-lg

Dropdown:
rounded-lg

Modal:
rounded-xl

Status badge:
rounded-full

Avatar:
rounded-full

Icon container:
rounded-lg

IMPORTANT:

Radius harus konsisten.

Jangan mencampur rounded-md, rounded-lg, rounded-xl,
rounded-2xl secara random.

Default CIMS:
rounded-xl untuk major surfaces.

Hindari rounded-2xl pada hampir semua elemen karena membuat UI
terlihat seperti template AI/mobile app.

==================================================
6. BORDER & SHADOW
==================================================

Cards:

bg-white
border border-slate-200
shadow-sm

Gunakan shadow sangat halus.

Jangan:

shadow-xl
shadow-2xl
drop-shadow
glow
colored shadow

Jangan menggunakan border tebal sebagai dekorasi.

Hierarchy harus berasal dari:

spacing
typography
surface
border
position

bukan dari shadow besar.

==================================================
7. SPACING SYSTEM
==================================================

Gunakan spacing yang konsisten:

4px  = micro spacing
8px  = tight
12px = compact
16px = standard
20px = component
24px = card
32px = section
40px+ = major separation

Card padding default:

p-5

Large analytical card:

p-6

Page:

px-6 py-6

Desktop:

px-8

Jangan membuat semua komponen terlalu rapat.

Jangan membuat semua komponen terlalu besar.

==================================================
8. TYPOGRAPHY
==================================================

Gunakan SATU font utama secara konsisten.

Preferred:

Inter

atau font system yang sudah digunakan project.

JANGAN mencampur Inter + Figtree + Jakarta Sans tanpa alasan.

Hierarchy:

Page title:
text-2xl
font-semibold
tracking-tight

Section title:
text-base
font-semibold

Card title:
text-sm
font-semibold

Metric:
text-3xl
font-semibold
tracking-tight

Large metric:
text-4xl
font-semibold
tracking-tight

Body:
text-sm
font-normal

Secondary:
text-xs
text-slate-500

Navigation:
text-sm
font-medium

Caption:
text-xs
text-slate-500

JANGAN menggunakan:

uppercase
tracking-widest
font-black

untuk UI biasa.

Uppercase hanya untuk label metadata yang benar-benar membutuhkan
distinction.

==================================================
9. ICON SYSTEM
==================================================

Gunakan ICON SYSTEM CIMS yang sudah ada:

resources/js/Components/Cims/icons.jsx

JANGAN mengganti custom CIMS SVG icons dengan Lucide.

Icons harus:

- simple
- stroke-based
- consistent stroke width
- currentColor
- aria-hidden jika decorative
- accessible label jika interactive

Ukuran:

Navigation:
18–20px

Card icon:
18–20px

Header:
18–20px

Primary action:
18px

Jangan menggunakan icon besar hanya untuk membuat card terlihat menarik.

Jangan menggunakan emoji.

Jangan menggunakan random icon library.

==================================================
10. SIDEBAR
==================================================

Sidebar harus terasa seperti operational navigation,
bukan decorative landing page.

Gunakan:

background: white
border-right: 1px solid #E2E8F0

Navigation:

default:
text-slate-600

hover:
bg-slate-50
text-slate-900

active:
bg-blue-50
text-blue-700

Active navigation boleh memiliki visual emphasis,
tetapi JANGAN menggunakan giant blue blocks.

Gunakan icon + label.

Spacing antar navigation item harus lega.

Navigation hierarchy harus jelas:

MAIN
- Dashboard
- Monitoring
- Devices

MANAGEMENT
- Master Data
- Hotspot
- Vouchers
- Users

SYSTEM
- Maintenance
- Reports
- Settings

Gunakan section label kecil dan muted.

==================================================
11. HEADER
==================================================

Header:

white
border-bottom border-slate-200

Tidak perlu shadow besar.

Search:

rounded-lg
border border-slate-200
bg-white

Placeholder:

"Cari perangkat, pengguna, atau lokasi..."

Search tidak boleh terlihat seperti decorative pill.

Action:

Primary:
bg-blue-600
text-white
rounded-lg

Secondary:
white
border border-slate-200
text-slate-700
rounded-lg

Notification:

Gunakan icon CIMS.

Badge hanya ketika unread count memang tersedia.

Jangan membuat fake notification.

==================================================
12. DASHBOARD METRIC CARDS
==================================================

Metric cards harus sederhana.

Contoh:

Total Perangkat
48

Online
36

Bandwidth
1.2 Gbps

Total Pengguna
1,254

Jangan membuat setiap metric card memiliki:

- giant colorful icon
- gradient
- glow
- decorative background
- random illustration

Gunakan hierarchy:

label
metric
context/trend

Metric angka harus menjadi focal point.

==================================================
13. STATUS
==================================================

Status menggunakan semantic color.

Online:
green

Degraded:
amber

Offline:
red

Unknown:
slate

Status tidak boleh hanya dibedakan melalui warna.

Gunakan:

dot + text

Contoh:

● Online

Bukan hanya:

●

Gunakan vocabulary dari:

Components/Cims/theme.jsx

Jangan membuat status vocabulary baru di setiap page.

==================================================
14. CHARTS
==================================================

Chart harus terlihat seperti monitoring tool,
bukan marketing analytics template.

Gunakan blue sebagai primary series.

Secondary series dapat menggunakan:

slate
light blue

Semantic colors hanya jika maknanya memang status.

Grid:

sangat subtle.

Jangan menggunakan:

gradient area besar
glow
3D chart
excessive colors
heavy borders

Chart harus memiliki:

- clear axis
- readable labels
- useful tooltip
- meaningful empty state

Jangan invent data.

Gunakan data dari backend/controller yang sudah ada.

==================================================
15. TABLE / DEVICE LIST
==================================================

Table harus menjadi operational interface.

Gunakan:

compact row
clear column hierarchy
subtle divider

Hover:

bg-slate-50

Jangan membuat setiap row menjadi individual card.

Status:

dot + text

Actions:

gunakan icon yang konsisten.

==================================================
16. CARD COMPOSITION
==================================================

Jangan membuat semua card memiliki ukuran sama.

Gunakan hierarchy:

PRIMARY
Large analytical card

SECONDARY
Supporting card

TERTIARY
Small operational card

Dashboard boleh menggunakan asymmetric composition seperti referensi.

Contoh:

┌─────────────────────────────┬──────────────┐
│                             │              │
│ Network Traffic             │ Device       │
│                             │ Status       │
│                             │              │
├──────────────────────┬──────┤              │
│ Recent Transactions  │      │              │
│                      │ Alerts              │
└──────────────────────┴──────┴──────────────┘

Namun jangan memaksakan bento-grid jika datanya tidak membutuhkan.

==================================================
17. RESPONSIVE
==================================================

Desktop:
Sidebar persistent.

Tablet:
Sidebar dapat collapse.

Mobile:
Navigation menjadi drawer.

Cards:

desktop:
multi-column

tablet:
2 columns jika masih readable

mobile:
1 column

JANGAN membuat 4–5 metric cards tetap berjajar di mobile.

Table pada mobile:

gunakan horizontal overflow atau responsive transformation
jika diperlukan.

Tidak boleh ada:

horizontal page overflow
text clipping
button keluar viewport
chart terpotong

==================================================
18. MOTION
==================================================

Animation harus purposeful.

Default:

transition-colors
transition-opacity

Duration:

150–200ms

Jangan menggunakan:

animate-pulse
animate-bounce
animate-spin

secara dekoratif.

Online status NORMAL tidak boleh berkedip/pulse.

Respect:

prefers-reduced-motion.

==================================================
19. ACCESSIBILITY
==================================================

Semua interactive element:

- keyboard accessible
- visible focus state
- semantic HTML
- accessible name
- sufficient contrast

Jangan menghapus outline tanpa replacement.

Color bukan satu-satunya cara menyampaikan status.

Minimum target:

WCAG AA.

==================================================
20. AI-SLOP PROHIBITIONS
==================================================

DILARANG:

❌ Blue-purple gradient
❌ Green/emerald branding
❌ Neon glow
❌ Glassmorphism
❌ Decorative grid background
❌ Excessive rounded-full
❌ Every element inside a card
❌ Excessive shadows
❌ Huge gradient hero
❌ Emoji icons
❌ Random icon libraries
❌ Fake statistics
❌ Fake activity
❌ Fake trends
❌ Fake notifications
❌ Generic "Welcome back!"
❌ Generic marketing copy
❌ "AI-powered" badges tanpa fungsi
❌ Excessive pills
❌ Excessive badges
❌ 3-step generic layouts
❌ Bento grid hanya karena terlihat modern
❌ Every card having a colorful icon circle
❌ Every section having blue accent
❌ Unnecessary animation
❌ Copy-paste card layouts

==================================================
21. CIMS DESIGN PHILOSOPHY
==================================================

Prioritaskan:

1. Information hierarchy
2. Usability
3. Readability
4. Consistency
5. Accessibility
6. Data integrity
7. Visual polish

Bukan:

1. Decoration
2. Trends
3. Animation
4. Gradients

Jika sebuah visual element tidak membantu user memahami,
menavigasi, atau mengoperasikan sistem:

JANGAN TAMBAHKAN.

==================================================
22. IMPLEMENTATION RULE
==================================================

JANGAN melakukan mass find/replace.

JANGAN:

"replace emerald with blue"

"replace dark with white"

"replace rounded with rounded-xl"

secara global.

Gunakan:

DESIGN TOKEN
→ COMPONENT
→ PAGE

Perubahan harus intentional.

Pertahankan business logic.

Pertahankan API/controller.

Pertahankan data nyata.

Pertahankan custom CIMS icons.

Jangan melakukan redesign hanya berdasarkan asumsi.

==================================================
23. FINAL QUALITY CHECK
==================================================

Sebelum menyatakan UI selesai, evaluasi:

[ ] Apakah terlihat seperti produk enterprise nyata?
[ ] Apakah visual hierarchy jelas?
[ ] Apakah whitespace cukup?
[ ] Apakah terlalu banyak card?
[ ] Apakah terlalu banyak rounded corners?
[ ] Apakah terlalu banyak blue?
[ ] Apakah ada emerald/gradient/glow residue?
[ ] Apakah typography konsisten?
[ ] Apakah icon konsisten?
[ ] Apakah status semantic?
[ ] Apakah data benar-benar berasal dari backend?
[ ] Apakah keyboard accessible?
[ ] Apakah mobile usable?
[ ] Apakah ada unnecessary animation?
[ ] Apakah ada pola AI-slop?
[ ] Apakah desain masih terasa seperti CIMS,
    bukan template dashboard generik?

Jika jawabannya "ya" untuk pola AI-slop:

REVISE sebelum selesai.
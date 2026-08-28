# SafeMap — PHP + MySQL backend

এই package-এ তোমার existing SafeMap frontend-কে PHP + MySQL backend-এর সঙ্গে যুক্ত করা হয়েছে।

## Folder structure

```text
/
├── index.html
├── app.js
├── database.sql
├── .htaccess
├── api/
│   ├── _common.php
│   ├── data.php
│   ├── locations.php
│   └── report.php
├── config/
│   ├── database.example.php
│   └── database.php
└── uploads/
    └── reports/
```

## InfinityFree setup

1. Hosting account তৈরি করে একটি MySQL database তৈরি করো।
2. `database.sql`-এর SQL phpMyAdmin-এ import করো। যদি hosting provider database create করার সময় database name নিজে দেয়, `CREATE DATABASE` এবং `USE safemap` অংশ প্রয়োজন হলে বাদ দিয়ে সেই database select করে বাকি SQL চালাও।
3. `config/database.php`-এ hosting থেকে পাওয়া:
   - DB_HOST
   - DB_NAME
   - DB_USER
   - DB_PASS
   বসাও।
4. সব files `public_html`-এ upload করো।
5. `uploads/reports` writable আছে কি না দেখো। সাধারণত 755 যথেষ্ট; provider প্রয়োজন হলে 775 ব্যবহার করো।
6. Browser-এ site খুলে একটি test report submit করো।

## Backend behavior

- Report `POST /api/report.php`-এ যায়।
- Image server-side MIME check করার পরে random filename-এ save হয়।
- GD/WebP support থাকলে image 1600px-এর মধ্যে resize করে WebP quality 75-এ save করার চেষ্টা করে।
- একই report location-এর 100 মিটারের মধ্যে আগে location থাকলে নতুন location তৈরি না করে সেটির `report_count`, `use_count`/`sale_count` বাড়ায়।
- 100m search আগে latitude/longitude bounding box দিয়ে candidate কমায়, পরে exact Haversine distance যাচাই করে।
- Map data `GET /api/locations.php` থেকে আসে; map bounds দিলে শুধু viewport-এর locations আসে।
- Statistics এবং police-station table `GET /api/data.php` থেকে আসে।
- Public abuse guard হিসেবে একই IP fingerprint থেকে এক ঘণ্টায় 20 report limit রাখা হয়েছে।
- Contact information optional; user `yes` বললে তবেই save হয়।

## Police station coordinates

বর্তমান UI-তে report থেকে police station নির্বাচন করা নেই। তাই নতুন location-এর `police_station_id` প্রথমে NULL থাকবে এবং station report counts zero থাকতে পারে।

যদি পরে nearest police station auto-assign করতে চাও, `police_stations.latitude` এবং `longitude` পূরণ করে backend-এ nearest-station lookup যোগ করা যাবে।

## গুরুত্বপূর্ণ

`config/database.php` public repository-তে commit করবে না। এটি hosting credentials রাখে।

## Quick test
`/api/health.php` খুললে `database: connected` দেখালে PHP → MySQL connection ঠিক আছে।

# madok — PHP + MySQL backend

এই package-এ তোমার existing Madok frontend-কে PHP + MySQL backend-এর সঙ্গে যুক্ত করা হয়েছে।

## Folder structure

```text
/
├── public/
│   ├── index.php
│   ├── reports.php
│   ├── index.html
│   ├── reports.html
│   ├── assets/
│   ├── api/
│   └── uploads/
├── config/
├── data/
├── storage/
├── database.sql
├── .htaccess
└── tools/
```

## নিরাপদ production setup

1. Hosting account তৈরি করে একটি MySQL database তৈরি করো।
2. `database.sql`-এর SQL phpMyAdmin-এ import করো। যদি hosting provider database create করার সময় database name নিজে দেয়, `CREATE DATABASE` এবং `USE madok` অংশ প্রয়োজন হলে বাদ দিয়ে সেই database select করে বাকি SQL চালাও।
3. সবচেয়ে নিরাপদভাবে domain-এর document root `public/` directory-তে point করো; database/config/data/tools/storage `.htaccess` দিয়ে blocked থাকলেও project root-কে document root না করাই ভালো।
4. আসল `.env` web root-এর বাইরে রাখো এবং `ENV_FILE` environment variable দিয়ে তার absolute path দাও। `.env.example`-এ শুধু নমুনা values আছে।
5. `DB_USER`-এর জন্য least-privilege MySQL user ব্যবহার করো; root বা blank password ব্যবহার করো না।
6. `uploads/reports` writable রাখো, কিন্তু সেখানে PHP execution নিষিদ্ধ থাকবে। `storage/rate-limit` writable এবং public access থেকে blocked থাকতে হবে।
7. সব production traffic HTTPS-এ চালাও; Cloudflare হলে SSL/TLS `Full (strict)`, Always Use HTTPS এবং WAF/rate limiting চালু করো।
8. Browser-এ site খুলে একটি test report submit করো।

## Backend behavior

- Report `POST /api/report.php`-এ যায়।
- Image server-side MIME check করার পরে random filename-এ save হয়।
- GD/WebP support থাকলে image 1600px-এর মধ্যে resize করে WebP quality 75-এ save করার চেষ্টা করে।
- একই report location-এর 100 মিটারের মধ্যে আগে location থাকলে নতুন location তৈরি না করে সেটির `report_count`, `use_count`/`sale_count` বাড়ায়।
- 100m search আগে latitude/longitude bounding box দিয়ে candidate কমায়, পরে exact Haversine distance যাচাই করে।
- Map data `GET /api/locations.php` থেকে আসে; map bounds দিলে শুধু viewport-এর locations আসে।
- Statistics এবং police-station table `GET /api/data.php` থেকে আসে।
- সব API request-এর জন্য file-backed global IP limit এবং write endpoint-এর জন্য আলাদা limit রাখা হয়েছে।
- Report form-এ honeypot আছে; filled হলে response সফল দেখিয়ে request বাতিল করা হয়।
- Cross-origin এবং cross-site Fetch Metadata request block করা হয়।
- Contact information optional; user `yes` বললে তবেই save হয়।

## Police station coordinates

বর্তমান UI-তে report থেকে police station নির্বাচন করা নেই। তাই নতুন location-এর `police_station_id` প্রথমে NULL থাকবে এবং station report counts zero থাকতে পারে।

যদি পরে nearest police station auto-assign করতে চাও, `police_stations.latitude` এবং `longitude` পূরণ করে backend-এ nearest-station lookup যোগ করা যাবে।

## গুরুত্বপূর্ণ

`config/database.php`, `.env`, database dump, `storage/`, `data/`, `tools/` public repository বা public document root-এ রাখবে না। বর্তমান `.htaccess` এগুলো block করে, তবে আলাদা `public/` document root আরও নিরাপদ।

## Quick test

Production-এ health endpoint public রাখা হয়নি; প্রয়োজনে hosting-এর local/admin-only check দিয়ে PHP → MySQL connection যাচাই করো।

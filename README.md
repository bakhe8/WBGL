# WBGL - نظام إدارة الضمانات البنكية v3.0

[![PHP Version](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-Private-red.svg)]()
[![Status](https://img.shields.io/badge/status-Active-success.svg)]()

## 📋 نظرة عامة

**WBGL** (Bank Guarantee Letters) هو نظام شامل لإدارة الضمانات البنكية مع ميزات الذكاء الاصطناعي للمطابقة التلقائية.

### ✨ المميزات الرئيسية

- 📦 **إدارة الدفعات**: استيراد ومعالجة دفعات الضمانات من Excel
- 🤖 **AI Matching**: مطابقة تلقائية للموردين والبنوك باستخدام التعلم الآلي
- 📊 **إحصائيات متقدمة**: تحليلات شاملة للأداء والاتجاهات
- 🖨️ **طباعة الخطابات**: إنشاء خطابات رسمية (إفراج/تمديد/تخفيض)
- ⚙️ **إعدادات مرنة**: تحكم كامل في معايير المطابقة والتعلم
- 🎨 **UI/UX موحد**: نظام تصميم متجاوب بدون اعتماديات خارجية

---

## 🏗️ البنية التقنية

### Stack

- **Backend**: PHP 8.3+ (Vanilla - no framework)
- **Database**: SQLite 3
- **Frontend**: Vanilla JavaScript + Custom CSS Design System
- **Icons**: Lucide Icons
- **Fonts**: Tajawal (Google Fonts)

### الهيكل

```
WBGL/
├── app/                  # Core application logic
│   ├── Core/            # Database, Router, Request handling
│   ├── Services/        # Business logic (AI, Matching, Letters)
│   └── Support/         # Helpers, Settings, DateTime
├── public/              # Public assets
│   ├── css/            # Design system CSS
│   └── uploads/        # Excel imports
├── views/              # Page templates
├── partials/           # Reusable components
├── api/                # API endpoints
└── docs/               # Documentation

```

---

## 🚀 التثبيت والتشغيل

### المتطلبات

- PHP 8.3 or higher
- SQLite3 extension enabled
- Composer (optional)

### التشغيل السريع

```bash
# Clone the repository
git clone https://github.com/bakhe8/WBGL.git
cd WBGL

# Windows (موصى به): تشغيل مباشر موحد على 8181
./toggle.bat

# عبر السكربت الموحد (الملف الوحيد لإدارة السيرفر)
./wbgl_server.ps1 -Action start -Port 8181
./wbgl_server.ps1 -Action stop -Port 8181
./wbgl_server.ps1 -Action restart -Port 8181 -OpenBrowser
./wbgl_server.ps1 -Action toggle

# تشغيل يدوي مباشر (إذا رغبت)
php -S localhost:8181 server.php

# Open in browser
http://localhost:<PORT>
```

### Database Setup

السيرفر سينشئ قاعدة البيانات تلقائياً عند أول تشغيل:

- `database.db` - SQLite database
- جداول تُنشأ تلقائياً إذا لم تكن موجودة

## 📝 الترخيص

هذا المشروع خاص ومملوك. جميع الحقوق محفوظة.

---

## 🎉 الإصدارات


**Made with ❤️ in Saudi Arabia**

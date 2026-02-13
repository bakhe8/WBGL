# WBGL - نظام إدارة الضمانات البنكية v3.0

[![PHP Version](https://img.shields.io/badge/PHP-8.3+-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-Private-red.svg)]()
[![Status](https://img.shields.io/badge/status-Active-success.svg)]()

## 📋 نظرة عامة

**WBGL** (Bank Guarantee Letters v3.0) هو نظام شامل لإدارة الضمانات البنكية مع ميزات الذكاء الاصطناعي للمطابقة التلقائية.

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

# Start development server
php -S localhost:8000

# Open in browser
http://localhost:8000
```

### Database Setup

السيرفر سينشئ قاعدة البيانات تلقائياً عند أول تشغيل:

- `database.db` - SQLite database
- جداول تُنشأ تلقائياً إذا لم تكن موجودة

---

## 🤝 المساهمة

نرحب بمساهماتك! يرجى اتباع العملية التالية:

### 1️⃣ فتح Issue

قبل البدء بأي عمل، افتح Issue لـ:

- 🐛 الإبلاغ عن bug
- ✨ اقتراح feature جديد
- 📝 تحسين documentation
- 💡 مناقشة قرار تقني

**استخدم Labels المناسبة:**

- `bug` - مشاكل تقنية
- `feature` - ميزات جديدة
- `improvement` - تحسينات على كود موجود
- `documentation` - تحديثات documentation
- `decision` - قرارات تقنية تحتاج نقاش

### 2️⃣ إنشاء Branch

```bash
# Always branch from main
git checkout main
git pull origin main

# Create feature branch
git checkout -b feature/your-feature-name
# OR
git checkout -b fix/bug-description
```

### 3️⃣ Commit Changes

```bash
# Make your changes
git add .
git commit -m "Clear description of what changed

- Detailed point 1
- Detailed point 2
- Fixes #issue_number"
```

### 4️⃣ إنشاء Pull Request

- Push your branch
- افتح PR على GitHub
- اربط PR بالـ Issue المناسب
- انتظر المراجعة

**⚠️ مهم:**

- لا يُسمح بالـ commit مباشرة على `main`
- جميع التغييرات يجب أن تمر عبر Pull Request
- يجب نجاح جميع الـ checks قبل الدمج

---

## 📚 الوثائق

- [Architecture Overview](https://github.com/bakhe8/WBGL/wiki/Architecture) - البنية المعمارية
- [AI Matching System](https://github.com/bakhe8/WBGL/wiki/AI-Matching) - نظام المطابقة الذكية
- [Design System](https://github.com/bakhe8/WBGL/wiki/Design-System) - نظام التصميم
- [API Reference](https://github.com/bakhe8/WBGL/wiki/API) - مرجع APIs
- [Decisions Log](https://github.com/bakhe8/WBGL/wiki/Decisions) - سجل القرارات التقنية

---

## 🔒 الأمان

- لا تشارك بيانات حساسة في Issues أو PRs
- استخدم `.env` للمعلومات السرية (غير موجود في Git)
- الإبلاغ عن ثغرات أمنية عبر email مباشر (لا تفتح Issue عام)

---

## 📞 الدعم

- **Issues**: للمشاكل التقنية والطلبات
- **Discussions**: للنقاشات والأسئلة العامة
- **Wiki**: للوثائق الشاملة

---

## 📝 الترخيص

هذا المشروع خاص ومملوك. جميع الحقوق محفوظة.

---

## 🎉 الإصدارات

### v3.0.0 (2026-01-10)

- ✅ نظام تصميم موحد (Design System)
- ✅ Unified Header Component
- ✅ إزالة Tailwind CDN
- ✅ إصلاح مشاكل التمرير والتنقل
- ✅ دعم Safari (webkit prefixes)
- ✅ +1557 additions, -585 deletions

---

**Made with ❤️ in Saudi Arabia**

/**
 * Controller: Smart Workstation
 * Manages the multi-guarantee batch entry workstation
 */
window.SmartWorkstation = {
    state: {
        draftId: null,
        entries: [], // Array of guarantee objects
        currentIndex: 0,
        pdfUrl: null
    },

    init() {
        console.log('Smart Workstation Controller Initialized');
        this.bindEvents();
    },

    bindEvents() {
        const btnClose = document.getElementById('btnCloseWorkstation');
        const btnPrev = document.getElementById('btnWsPrev');
        const btnNext = document.getElementById('btnWsNext');
        const btnFinish = document.getElementById('btnWsFinish');
        const btnReset = document.getElementById('btnWorkstationReset');
        const form = document.getElementById('workstationForm');

        if (btnClose) btnClose.onclick = () => this.close();
        if (btnPrev) btnPrev.onclick = () => this.prevEntry();
        if (btnNext) btnNext.onclick = () => this.nextEntry();
        if (btnFinish) btnFinish.onclick = () => this.finish();
        if (btnReset) btnReset.onclick = () => this.resetForm();

        if (form) {
            form.onsubmit = (e) => {
                e.preventDefault();
                this.nextEntry();
            };
        }
    },

    open(draftData) {
        console.log('Opening Workstation for Draft:', draftData);

        // Reset state
        this.state.draftId = draftData.id;
        this.state.currentIndex = 0;

        // Find best PDF among attachments
        let pdfFile = null;
        if (draftData.evidence_files) {
            pdfFile = draftData.evidence_files.find(f => f.name.toLowerCase().endsWith('.pdf'));
        }

        if (!pdfFile) {
            Swal.fire('خطأ', 'لم يتم العثور على ملف PDF في هذا الإيميل.', 'error');
            return;
        }

        // Fix: resolve path relative to public uploads
        // Paths from DB are like 'uploads/guarantees/ID/file.pdf'
        this.state.pdfUrl = `/public/${pdfFile.path}`;

        document.getElementById('pdfFileName').textContent = pdfFile.name;
        document.getElementById('workstationPdfViewer').src = this.state.pdfUrl;

        // Initialize with first entry from draft data
        this.state.entries = [{
            guarantee_number: draftData.guarantee_number || '',
            supplier: draftData.supplier_name || '',
            bank: draftData.bank_name || '',
            amount: draftData.amount || '',
            contract_number: draftData.contract_number || '',
            expiry_date: draftData.expiry_date || '',
            type: draftData.type || '',
            related_to: draftData.related_to || 'contract',
            comment: draftData.details || ''
        }];

        this.renderEntry();
        document.getElementById('smartWorkstation').style.display = 'flex';
        document.body.style.overflow = 'hidden'; // Lock scrolling
    },

    close() {
        if (this.state.entries.length > 1 || this.isFormModified()) {
            if (!confirm('لديك بيانات غير محفوظة في هذه الجلسة. هل تريد الإغلاق؟')) return;
        }
        document.getElementById('smartWorkstation').style.display = 'none';
        document.body.style.overflow = '';
    },

    isFormModified() {
        // Simple check if first entry has any data beyond defaults
        const e = this.state.entries[0];
        return e.guarantee_number || e.amount;
    },

    saveCurrentToState() {
        const entry = {
            guarantee_number: document.getElementById('wsGuarantee').value,
            supplier: document.getElementById('wsSupplier').value,
            bank: document.getElementById('wsBank').value,
            amount: document.getElementById('wsAmount').value,
            contract_number: document.getElementById('wsContract').value,
            expiry_date: document.getElementById('wsExpiry').value,
            type: document.getElementById('wsType').value,
            comment: document.getElementById('wsComment').value
        };
        this.state.entries[this.state.currentIndex] = entry;
    },

    renderEntry() {
        const entry = this.state.entries[this.state.currentIndex];
        document.getElementById('wsGuarantee').value = entry.guarantee_number || '';
        document.getElementById('wsSupplier').value = entry.supplier || '';
        document.getElementById('wsBank').value = entry.bank || '';
        document.getElementById('wsAmount').value = entry.amount || '';
        document.getElementById('wsContract').value = entry.contract_number || '';
        document.getElementById('wsExpiry').value = entry.expiry_date || '';
        document.getElementById('wsType').value = entry.type || '';
        document.getElementById('wsComment').value = entry.comment || '';

        // Update UI counters
        document.getElementById('currentEntryIndex').textContent = this.state.currentIndex + 1;
        document.getElementById('totalEntriesCount').textContent = this.state.entries.length;

        // Update Buttons
        document.getElementById('btnWsPrev').disabled = (this.state.currentIndex === 0);

        const isLast = (this.state.currentIndex === this.state.entries.length - 1);
        document.getElementById('btnWsNext').textContent = isLast ? '➕ إضافة جديد' : 'التالي ➡️';
    },

    nextEntry() {
        // Validate current form before moving
        if (!document.getElementById('wsGuarantee').value || !document.getElementById('wsAmount').value) {
            Swal.fire({ title: 'تنبيه', text: 'يرجى إدخال رقم الضمان والمبلغ أولاً', icon: 'warning', toast: true, position: 'top-end', showConfirmButton: false, timer: 3000 });
            return;
        }

        this.saveCurrentToState();

        if (this.state.currentIndex < this.state.entries.length - 1) {
            this.state.currentIndex++;
        } else {
            // Create new entry - clean start for independent data
            this.state.entries.push({
                guarantee_number: '',
                amount: '',
                supplier: '',
                bank: '',
                contract_number: '',
                expiry_date: '',
                type: '',
                comment: ''
            });
            this.state.currentIndex++;
        }
        this.renderEntry();
        document.getElementById('wsGuarantee').focus();
    },

    prevEntry() {
        this.saveCurrentToState();
        if (this.state.currentIndex > 0) {
            this.state.currentIndex--;
            this.renderEntry();
        }
    },

    resetForm() {
        if (!confirm('هل تريد مسح بيانات الضمان الحالي؟')) return;
        document.getElementById('wsGuarantee').value = '';
        document.getElementById('wsAmount').value = '';
        document.getElementById('wsExpiry').value = '';
        document.getElementById('wsComment').value = '';
    },

    async finish() {
        this.saveCurrentToState(); // Save last record

        // Validate all entries
        const invalid = this.state.entries.find(e => !e.guarantee_number || !e.amount);
        if (invalid) {
            Swal.fire('خطأ', 'يرجى إكمال بيانات كافة الضمانات التي قمت بإضافتها (رقم الضمان والمبلغ مطلوبان)', 'error');
            return;
        }

        const result = await Swal.fire({
            title: 'تأكيد الحفظ',
            text: `هل أنت متأكد من حفظ ${this.state.entries.length} ضمانات في قاعدة البيانات؟`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'نعم، حفظ الكل',
            cancelButtonText: 'إلغاء',
            confirmButtonColor: '#059669'
        });

        if (!result.isConfirmed) return;

        Swal.showLoading();
        try {
            const response = await fetch('/api/commit-batch-draft.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    draft_id: this.state.draft_id || this.state.draftId,
                    guarantees: this.state.entries
                })
            });

            const data = await response.json();
            if (data.success) {
                await Swal.fire('تم بنجاح', data.message, 'success');
                window.location.href = `/index.php?id=${data.redirect_id}`;
            } else {
                throw new Error(data.error);
            }
        } catch (error) {
            Swal.fire('فشل الحفظ', error.message, 'error');
        }
    }
};

// Auto-init
document.addEventListener('DOMContentLoaded', () => window.SmartWorkstation.init());

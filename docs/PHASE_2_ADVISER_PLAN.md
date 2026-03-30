# Phase 2: Adviser Module Organization Plan

**Date:** October 18, 2025  
**Target:** Move and organize all adviser-related files  
**Estimated Files:** 13 files

---

## 📋 Files to Move

### Adviser Files in Root (13 files):

| Current Location | New Location | Description |
|-----------------|--------------|-------------|
| `home_page_adviser.php` | `adviser/index.php` | Adviser dashboard |
| `adviser_login.php` | `adviser/login.php` | Adviser login page |
| `adviser_login_process.php` | `adviser/login_process.php` | Login handler |
| `logout_adviser.php` | `adviser/logout.php` | Logout handler |
| `adviser_management.php` | `adviser/management.php` | Adviser management |
| `acc_mng_adviser.php` | `adviser/account_management.php` | Student account mgmt |
| `pending_accs_adviser.php` | `adviser/pending_accounts.php` | Pending approvals |
| `approve_account_adviser.php` | `adviser/approve_account.php` | Approve handler |
| `reject_adviser.php` | `adviser/reject_account.php` | Reject handler |
| `checklist_adviser.php` | `adviser/checklist.php` | Checklist management |
| `checklist_eval_adviser.php` | `adviser/checklist_eval.php` | Checklist evaluation |
| `adviser_input_form.html` | `adviser/input_form.html` | Adviser creation form |
| `adviser_connection.php` | Root (keep for now) | Connection handler |

---

## 🎯 Path Updates Required

### For Files in `/adviser/` Folder:

1. **Config Includes:**
   - `require_once __DIR__ . '/config/config.php'`
   - → `require_once __DIR__ . '/../config/config.php'`

2. **Images/Logos:**
   - `img/cav.png` → `../img/cav.png`
   - `pix/home1.png` → `../pix/home1.png`

3. **Redirects:**
   - `home_page_adviser.php` → `index.php`
   - `adviser_login.php` → `login.php`
   - `logout_adviser.php` → `logout.php`

4. **Cross-References:**
   - `pending_accs_adviser.php` → `pending_accounts.php`
   - `approve_account_adviser.php` → `approve_account.php`
   - `reject_adviser.php` → `reject_account.php`

5. **Root Files:**
   - `list_of_students.php` → `../list_of_students.php`
   - `settings.html` → `../settings.html`
   - `pre_enroll.php` → `../pre_enroll.php` or `pre_enroll.php` (if moved)

---

## 📝 Execution Plan

### Step 1: Move Files (Batch 1 - Core Files)
Move the main adviser files:
- ✅ `home_page_adviser.php` → `adviser/index.php`
- ✅ `adviser_login.php` → `adviser/login.php`
- ✅ `adviser_login_process.php` → `adviser/login_process.php`
- ✅ `logout_adviser.php` → `adviser/logout.php`

### Step 2: Update Core Files
Fix paths in moved files:
- Config includes
- Image paths
- Login/logout redirects

### Step 3: Test Core Flow
Verify:
- [ ] Adviser login works
- [ ] Dashboard loads
- [ ] Images display
- [ ] Logout works

### Step 4: Move Files (Batch 2 - Management Files)
Move management files:
- ✅ `adviser_management.php` → `adviser/management.php`
- ✅ `acc_mng_adviser.php` → `adviser/account_management.php`
- ✅ `pending_accs_adviser.php` → `adviser/pending_accounts.php`

### Step 5: Move Files (Batch 3 - Action Files)
Move action handlers:
- ✅ `approve_account_adviser.php` → `adviser/approve_account.php`
- ✅ `reject_adviser.php` → `adviser/reject_account.php`
- ✅ `checklist_adviser.php` → `adviser/checklist.php`
- ✅ `checklist_eval_adviser.php` → `adviser/checklist_eval.php`

### Step 6: Move Files (Batch 4 - Form Files)
Move form files:
- ✅ `adviser_input_form.html` → `adviser/input_form.html`

### Step 7: Update All Paths
Fix all references in moved files

### Step 8: Update External References
Update files that link to adviser pages:
- `index.html` - Adviser login link
- Other pages referencing adviser URLs

### Step 9: Final Testing
Complete test of all adviser features

---

## 🔍 Files That Reference Adviser URLs

Need to update these files to point to new adviser locations:

1. `index.html` - Adviser login modal/link
2. `admin/index.php` - Create adviser link (if exists)
3. `admin/input_form.html` - Dashboard link
4. Any settings or navigation files

---

## ⚠️ Special Considerations

### Pre-Enrollment Files:
- `pre_enroll.php` - Shared between adviser and student?
- Need to determine: Keep in root or move to adviser?
- Decision: Keep in root for now (shared resource)

### Checklist Files:
- `checklist_adviser.php` - Adviser version
- `checklist_stud.php` - Student version (don't move yet)
- Keep separate versions in respective folders

### Connection Files:
- `adviser_connection.php` - Form handler
- Keep in root for now (used by input form)

---

## 🎯 Expected Results

After Phase 2 completion:

### Directory Structure:
```
PEAS/
├── admin/              ✅ Phase 1 Complete
│   ├── index.php
│   ├── login.php
│   └── ...
├── adviser/            ⏳ Phase 2 In Progress
│   ├── index.php
│   ├── login.php
│   ├── pending_accounts.php
│   ├── checklist.php
│   └── ...
├── config/             ✅ Existing
├── img/                ✅ Existing
├── pix/                ✅ Existing
└── uploads/            ✅ Existing
```

### URLs After Migration:
- Adviser Login: `http://localhost/PEAS/adviser/login.php`
- Adviser Dashboard: `http://localhost/PEAS/adviser/index.php`
- Pending Accounts: `http://localhost/PEAS/adviser/pending_accounts.php`
- Checklist: `http://localhost/PEAS/adviser/checklist.php`

---

## 📊 Progress Tracking

### Phase 2 Milestones:
- [ ] Batch 1: Core files moved
- [ ] Batch 1: Paths updated
- [ ] Batch 1: Tested
- [ ] Batch 2: Management files moved
- [ ] Batch 2: Paths updated
- [ ] Batch 3: Action files moved
- [ ] Batch 3: Paths updated
- [ ] Batch 4: Form files moved
- [ ] All external references updated
- [ ] Full testing complete
- [ ] Documentation updated

---

## 🚀 Ready to Start!

**Next Action:** Move Batch 1 (Core Files)

Shall we begin? 🎯

# Admin Module Visual Test Checklist 🎨

Test each item and mark with ✅ or ❌

---

## 1. Login Page Test
**URL:** `http://localhost/PEAS/admin/login.php`

- [ ] Page loads without errors
- [ ] CvSU favicon appears in browser tab
- [ ] Login form is visible and styled
- [ ] Username and password fields work
- [ ] "Back" button redirects to main page
- [ ] Can submit login form

---

## 2. Admin Dashboard Test
**URL:** `http://localhost/PEAS/admin/index.php` (after login)

### Header:
- [ ] Green header bar displays
- [ ] CvSU logo visible in header
- [ ] "PRE - ENROLLMENT ASSESSMENT" text shows
- [ ] Admin name displays: "YourName | Admin"
- [ ] Menu toggle button (☰) works

### Background:
- [ ] Background image (drone_cvsu_2.png) loads
- [ ] Background is not broken/blank

### Sidebar:
- [ ] Sidebar opens/closes with toggle
- [ ] "Admin Panel" heading shows
- [ ] All menu icons display:
  - [ ] Home icon (Dashboard)
  - [ ] Account icons (Create Adviser/Admin)
  - [ ] Pending icon
  - [ ] Student icon
  - [ ] Settings icon
  - [ ] Signout icon

### Main Content:
- [ ] Dashboard cards/options display
- [ ] Option icons are visible
- [ ] "Create Adviser Account" option
- [ ] "Create Admin Account" option
- [ ] "List of Students" option
- [ ] "Settings" option

---

## 3. Navigation Test

### Click Each Sidebar Link:

**Dashboard:**
- [ ] Clicking "Dashboard" stays on current page
- [ ] Link is highlighted/active

**Create Adviser Account:**
- [ ] Redirects to `admin/input_form.html`
- [ ] Page loads correctly
- [ ] Form displays properly

**Create Admin Account:**
- [ ] Redirects to `admin/input_form.html`
- [ ] Same as above

**Pending Accounts:**
- [ ] Redirects to `admin/pending_accounts_old.php`
- [ ] Page loads with table
- [ ] Shows pending student list (or "No pending" message)

**List of Students:**
- [ ] Redirects to `/PEAS/list_of_students.php`
- [ ] Page loads (even if in root folder)

**Settings:**
- [ ] Redirects to `/PEAS/settings.html`
- [ ] Page loads (even if in root folder)

**Sign Out:**
- [ ] Redirects to `admin/login.php`
- [ ] Session is destroyed
- [ ] Cannot access dashboard without login

---

## 4. Input Form Test
**URL:** `http://localhost/PEAS/admin/input_form.html`

### Visual Elements:
- [ ] Favicon displays
- [ ] Header logo shows
- [ ] Sidebar renders correctly
- [ ] All sidebar icons visible
- [ ] Form fields display properly

### Links:
- [ ] "Dashboard" link → returns to dashboard
- [ ] "Sign Out" link → logs out

---

## 5. Pending Accounts Test
**URL:** `http://localhost/PEAS/admin/pending_accounts_old.php`

### Visual:
- [ ] Header logo displays
- [ ] "Back to Dashboard" button shows
- [ ] Table renders correctly
- [ ] Approve/Reject buttons visible (if accounts pending)

### Functionality:
- [ ] "Back to Dashboard" → returns to `admin/index.php`
- [ ] Clicking approve → approves account
- [ ] Clicking reject → removes account

---

## 6. Browser Console Test

Press `F12` → Go to Console tab

- [ ] No 404 errors for images
- [ ] No 404 errors for CSS/JS files
- [ ] No JavaScript errors
- [ ] No warning messages about missing resources

---

## 7. Network Tab Test

Press `F12` → Go to Network tab → Refresh page

### Check These Resources Load Successfully (200 status):
- [ ] `admin/index.php` → 200 OK
- [ ] `../img/cav.png` → 200 OK
- [ ] `../img/drone_cvsu_2.png` → 200 OK
- [ ] `../pix/home1.png` → 200 OK
- [ ] `../pix/account.png` → 200 OK
- [ ] `../pix/pending.png` → 200 OK
- [ ] `../pix/student.png` → 200 OK
- [ ] `../pix/set.png` → 200 OK
- [ ] `../pix/singout.png` → 200 OK

**If any show 404:** Report the file path

---

## 8. Different Browsers Test (Optional)

Test in multiple browsers:
- [ ] Google Chrome - Works
- [ ] Mozilla Firefox - Works
- [ ] Microsoft Edge - Works

---

## Common Issues & Solutions

### Issue: Images Don't Load (Broken Image Icon)
**Solution:** Path is wrong, check browser console for 404 errors

### Issue: 404 Not Found when clicking links
**Solution:** Link points to old filename, report which link

### Issue: Page is unstyled (plain HTML)
**Solution:** CSS not loading, check if file exists

### Issue: Redirect loops
**Solution:** Session issue, clear cookies and try again

---

## Report Template

If you find any issues, copy this template:

```
❌ ISSUE FOUND:

Page: [URL where issue occurred]
Problem: [What's broken - e.g., "Logo doesn't display"]
Browser: [Chrome/Firefox/Edge]
Error: [Console error message, if any]
Screenshot: [Optional - if helpful]
```

---

## Success Criteria

**Admin module is working if:**
✅ All 12 admin files load without 404 errors  
✅ All images/logos display correctly  
✅ Navigation works (no broken links)  
✅ Login/logout functions properly  
✅ No errors in browser console  
✅ Sidebar icons all visible  

**If all checks pass → Ready for Phase 2 (Adviser files)!** 🚀

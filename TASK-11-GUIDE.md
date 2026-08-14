# Task 11 Guide: Employee Photo Upload and Attendance CSV Export

Task 11 adds two features:

- Admins and company administrators can upload and view employee profile
  photos from `/employees`.
- Company administrators can export their own company's attendance logs as a
  CSV file from `/attendance-logs` or through the JSON API.

The export deliberately takes the company from the authenticated user. A
request cannot choose another company by sending a different `company_id`.

## 1. What a storage symlink means

Laravel stores uploaded public files here:

```text
storage/app/public
```

That directory is not directly reachable by a browser. The browser can only
serve files under `public`, so Laravel creates this link:

```text
public/storage  --->  storage/app/public
```

A symlink is similar to a shortcut. It does not make a second copy of a file.
For example, this stored file:

```text
storage/app/public/employee-photos/avatar.png
```

becomes available in the browser at:

```text
http://localhost/storage/employee-photos/avatar.png
```

Create the link once after cloning or deploying the project:

```bash
php artisan storage:link
```

On this Windows workspace, `public/storage` has already been created as a
directory junction. A junction provides the same behavior for this local app.
The link is intentionally ignored by Git because every machine creates its own.

## 2. Database migration

The migration adds a nullable `photo_url` column to `employees`:

```text
database/migrations/2026_08_14_000001_add_photo_url_to_employees_table.php
```

Run it with:

```bash
php artisan migrate
```

The column stores a relative public-disk path such as:

```text
employee-photos/abc123.png
```

`Employee::photoUrl()` converts that stored path into a complete public URL.
Keeping the relative path makes the application portable when `APP_URL`
changes between local development and production.

## 3. Employee photo upload

Both the add and edit dialogs on `/employees` contain a **Profile photo**
field. The selected or currently stored image is shown as a preview, and the
employees table contains a photo column.

Accepted uploads are:

- JPG or JPEG
- PNG
- WebP
- Maximum size: 2 MB

Laravel validates and stores the image with:

```php
$request->file('photo')->store('employee-photos', 'public');
```

The edit form uses a multipart POST with `_method=put`. This is Laravel's
method-spoofing pattern: it reaches the existing PUT route while allowing PHP
to read the uploaded file correctly. If no new file is selected during an
edit, the existing photo is retained. When a new photo is saved, the previous
file is deleted.

Access remains controlled by the existing Laratrust employee permissions:

- `admin` can view and manage employees across companies.
- `company_admin` can view and manage employees in its own company.
- `employee` cannot open the company-wide `/employees` page.

## 4. CSV attendance export

The company administrator page has an **Export CSV** button. It downloads the
month currently shown by the attendance month picker.

Two routes expose the same exporter:

```text
GET /attendance-logs/export?month=2026-08
GET /api/time-logs/export?month=2026-08
```

The CSV contains these columns:

```text
Employee No., Employee, Company, Date, Time In, Time Out, Duration,
Status, Approved By, Approved At, Notes, Reject Reason
```

The file includes a UTF-8 byte-order mark so names and notes display correctly
when opened in Microsoft Excel. Values beginning with `=`, `+`, `-`, or `@`
are escaped to prevent spreadsheet formula injection.

## 5. Export authorization and company isolation

Both export routes require the Laratrust `company_admin` role. The API route
also uses the existing Sanctum authentication and `attendance-logs.view`
permission middleware.

The exporter performs these checks:

```text
Authenticated?
    |
    v
Has company_admin role and a company_id?
    | no -> 403 Forbidden
    v yes
Query attendance through employees whose company_id matches the user
    |
    v
Return only that company's CSV rows
```

There is intentionally no trusted `company_id` request parameter. Even a URL
such as this cannot export company 999 unless that is the authenticated user's
company:

```text
/api/time-logs/export?month=2026-08&company_id=999
```

The extra `company_id` is ignored, and the authenticated user's company still
wins. Regular employees and global admins receive `403 Forbidden`; Task 11
limits this particular export to company administrators.

## 6. Calling the API from Postman or curl

First obtain a Sanctum bearer token using a company administrator account:

```bash
curl -X POST http://127.0.0.1:8000/api/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@company1.com","password":"your-password","device_name":"task-11-test"}'
```

Copy the returned token and request the CSV:

```bash
curl "http://127.0.0.1:8000/api/time-logs/export?month=2026-08" \
  -H "Accept: text/csv" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  --output attendance.csv
```

The Vue page does not expose a token to JavaScript. It calls the same API route
with the browser session established by the normal `/login` page.

## 7. Files used by Task 11

- `database/migrations/2026_08_14_000001_add_photo_url_to_employees_table.php`
- `app/Models/Employee.php`
- `app/Http/Controllers/EmployeeController.php`
- `app/Http/Controllers/TimeLogExportController.php`
- `resources/js/Components/EntityFormDialog.vue`
- `resources/js/Composables/useCrudDialog.js`
- `resources/js/Pages/Employees/Index.vue`
- `resources/js/Pages/AttendanceLogs/AdminIndex.vue`
- `routes/web.php`
- `routes/api.php`
- `tests/Feature/Task11FileUploadExportTest.php`

## 8. Setup and verification

Check that the active PHP version satisfies the installed dependencies:

```bash
php -v
```

This workspace's installed Composer dependencies currently require PHP 8.4.1
or newer. After the correct PHP executable is active, run:

```bash
php artisan migrate
php artisan storage:link
php artisan test --filter=Task11FileUploadExportTest
npm run build
```

If `storage:link` says the link already exists, that is expected on this
workspace and it does not need to be recreated.

## 9. Manual browser test

1. Sign in as an `admin` or `company_admin`.
2. Open `/employees` and create an employee with a profile image.
3. Confirm the image appears in the dialog preview and employees table.
4. Edit the employee without choosing another image and confirm the existing
   image stays in place.
5. Edit again with a different image and confirm the preview changes.
6. Sign in as a `company_admin` and open `/attendance-logs`.
7. Choose a month and click **Export CSV**.
8. Open the file and confirm it contains only employees from that account's
   company.
9. Sign in as an `employee` and request `/api/time-logs/export`; confirm the API
   returns `403 Forbidden`.

## 10. Automated tests

`Task11FileUploadExportTest` verifies that:

- an image is stored on the public disk;
- its public URL is visible to both administrator roles;
- an API export includes the authenticated company's attendance rows;
- another company's employee numbers, notes, and rows do not leak;
- a supplied foreign `company_id` cannot change the export scope; and
- employee and global-admin roles cannot use the export.

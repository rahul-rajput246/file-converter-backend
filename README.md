# FileFlow — File Converter & Compressor (Laravel REST API Backend)

A robust, secure, and production-ready Laravel REST API backend for the **FileFlow** File Converter & Compressor application.

---

## 1. Installation

Clone or navigate into the backend repository and install dependencies using Composer:

```bash
cd backend-file-converter
composer install
```

Ensure the PHP GD extension is enabled in your `php.ini` (standard in XAMPP/PHP).

---

## 2. Environment Configuration

Copy the example environment file and generate the application key if not already done:

```bash
cp .env.example .env
php artisan key:generate
```

### Key Environment Variables

In `.env`, configure the frontend origin, maximum file size, and file retention duration:

```env
APP_NAME=FileFlow
APP_URL=http://localhost:8000

# React Frontend Origin for CORS validation
FRONTEND_URL=http://localhost:5173

# Maximum allowed file upload size in Kilobytes (102400 KB = 100 MB)
FILE_CONVERTER_MAX_FILE_SIZE=102400

# File retention lifetime in minutes before cleanup
FILE_CONVERTER_FILE_LIFETIME=60
```

- **`FRONTEND_URL`**: Controls the allowed origin in `config/cors.php`. Prevents unauthorized cross-origin requests.
- **`FILE_CONVERTER_MAX_FILE_SIZE`**: Sets the validation ceiling for uploaded files (in KB).
- **`FILE_CONVERTER_FILE_LIFETIME`**: The number of minutes processed files remain available in storage before being eligible for automated cleanup.

---

## 3. Start Backend Server

Start the Laravel development server on port 8000:

```bash
php artisan serve --port=8000
```

The backend API will be accessible at:
```text
http://localhost:8000
```

---

## 4. API Endpoints Documentation

All endpoints return uniform JSON responses.

### 4.1. Get Supported Formats

Returns the list of currently supported file formats for conversion and compression.

- **URL**: `/api/files/supported-formats`
- **Method**: `GET`
- **Headers**: `Accept: application/json`
- **Request Body**: None

#### Example Request
```http
GET /api/files/supported-formats HTTP/1.1
Host: localhost:8000
Accept: application/json
```

#### Example Response (200 OK)
```json
{
    "success": true,
    "formats": [
        "jpg",
        "jpeg",
        "png",
        "webp"
    ]
}
```

---

### 4.2. Convert File

Converts an uploaded image into the requested target format.

- **URL**: `/api/files/convert`
- **Method**: `POST`
- **Content-Type**: `multipart/form-data`
- **Request Fields**:
  - `file` (required, file, max 100 MB, mime types: image/jpeg, image/png, image/webp)
  - `format` (required, string, allowed: `jpg`, `jpeg`, `png`, `webp`)

#### Example Request (JavaScript Fetch)
```javascript
const formData = new FormData();
formData.append("file", selectedFile);
formData.append("format", "webp");

const response = await fetch("http://localhost:8000/api/files/convert", {
    method: "POST",
    headers: {
        "Accept": "application/json"
    },
    body: formData
});

const data = await response.json();
```

#### Example Response (200 OK)
```json
{
    "success": true,
    "message": "File converted successfully.",
    "filename": "converted_417c57c180248ab2.webp",
    "download_url": "http://localhost:8000/api/files/download/converted_417c57c180248ab2.webp"
}
```

#### Possible Errors
- **422 Unprocessable Content** (Validation error):
```json
{
    "success": false,
    "message": "The uploaded file or format is invalid.",
    "errors": {
        "format": [
            "The requested conversion format is not supported. Allowed formats: jpg, jpeg, png, webp"
        ]
    }
}
```
- **500 Internal Server Error** (Processing failure):
```json
{
    "success": false,
    "message": "Unable to process the file."
}
```

---

### 4.3. Compress File

Compresses an uploaded image according to the specified compression level (`low`, `medium`, `high`).

- **URL**: `/api/files/compress`
- **Method**: `POST`
- **Content-Type**: `multipart/form-data`
- **Request Fields**:
  - `file` (required, file, max 100 MB, mime types: image/jpeg, image/png, image/webp)
  - `compression_level` (required, string, allowed: `low`, `medium`, `high`)

#### Example Request (JavaScript Fetch)
```javascript
const formData = new FormData();
formData.append("file", selectedFile);
formData.append("compression_level", "medium");

const response = await fetch("http://localhost:8000/api/files/compress", {
    method: "POST",
    headers: {
        "Accept": "application/json"
    },
    body: formData
});

const data = await response.json();
```

#### Example Response (200 OK)
```json
{
    "success": true,
    "message": "File compressed successfully.",
    "original_size": 4200000,
    "processed_size": 1800000,
    "filename": "compressed_fbb8bf0fb00aa52d.jpg",
    "download_url": "http://localhost:8000/api/files/download/compressed_fbb8bf0fb00aa52d.jpg"
}
```

#### Possible Errors
- **422 Unprocessable Content** (Validation error):
```json
{
    "success": false,
    "message": "The uploaded file or compression level is invalid.",
    "errors": {
        "compression_level": [
            "The compression level must be one of: low, medium, high"
        ]
    }
}
```

---

### 4.4. Download Processed File

Securely streams the generated file as an attachment. Only files inside the internal processed storage matching cryptographic generated naming schemes are accessible.

- **URL**: `/api/files/download/{filename}`
- **Method**: `GET`
- **Parameters**:
  - `filename` (e.g. `converted_417c57c180248ab2.webp`)

#### Example Request
```http
GET /api/files/download/converted_417c57c180248ab2.webp HTTP/1.1
Host: localhost:8000
```

#### Example Response (200 OK)
- Binary stream with headers:
  - `Content-Disposition: attachment; filename="converted_417c57c180248ab2.webp"`
  - `Content-Type: image/webp`

#### Possible Errors
- **404 Not Found** (File missing, expired, or invalid filename pattern):
```json
{
    "success": false,
    "message": "File not found or link has expired."
}
```

---

## 5. Storage Architecture & Security

- **Temporary Uploads**: Stored in `storage/app/file-converter/uploads/` and immediately unlinked after transformation.
- **Processed Files**: Stored in `storage/app/file-converter/processed/`.
- **Filename Security**: Files are assigned unique cryptographic hashes (e.g., `converted_8f32a10be432f91a.webp`). User original filenames are never written to disk or served directly.
- **Path Traversal Protection**: Directory traversal attempts (e.g., `..`, `%2F`, slashes, null bytes) are blocked using regex verification and realpath containment checks.
- **No Database Dependency**: Operates entirely in memory and on disk without database requirements.

---

## 6. Automated Cleanup

To purge temporary and processed files that have exceeded the configured lifetime (`FILE_CONVERTER_FILE_LIFETIME`), run:

```bash
php artisan file-converter:cleanup
```

You can optionally override the lifetime in minutes:

```bash
php artisan file-converter:cleanup --minutes=30
```

### Scheduling with Laravel Cron
The cleanup command is pre-scheduled in `routes/console.php` to run hourly:
```php
\Illuminate\Support\Facades\Schedule::command('file-converter:cleanup')->hourly();
```
On production servers, add Laravel's worker cron to your crontab:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. Running Tests

To run the automated feature test suite (covering conversions, compressions, validations, downloads, security, and cleanup):

```bash
php artisan test
```

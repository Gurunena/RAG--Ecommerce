<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** PHP's own IMAGETYPE_* → the extension we will store it under. */
function upload_type_extension(int $imageType): ?string
{
    return [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ][$imageType] ?? null;
}

/** A human explanation for each of PHP's upload error codes. */
function upload_error_message(int $code): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That file is too large.';
        case UPLOAD_ERR_PARTIAL:
            return 'The upload was interrupted. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was chosen.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not save the file. Check the uploads folder is writable.';
        default:
            return 'That file could not be uploaded.';
    }
}

function store_uploaded_image(string $field, string $prefix = 'img'): string
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        json_fail('No file was received. Choose an image and try again.', 'VALIDATION', 400);
    }

    $file = $_FILES[$field];

    // An array here means the form sent multiple files under one name; this
    // helper stores exactly one, so refuse rather than silently take [0].
    if (is_array($file['tmp_name'])) {
        json_fail('Please upload one image at a time.', 'VALIDATION', 400);
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        json_fail(upload_error_message((int) $file['error']), 'VALIDATION', 400);
    }

    $max = (int) config('upload_max_bytes', 2 * 1024 * 1024);
    if ((int) $file['size'] <= 0) {
        json_fail('That file is empty.', 'VALIDATION', 400);
    }
    if ((int) $file['size'] > $max) {
        json_fail(
            sprintf('That image is larger than %dMB. Please use a smaller one.', (int) round($max / 1048576)),
            'VALIDATION',
            413
        );
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        app_log('Rejected a non-uploaded file', ['field' => $field, 'ip' => client_ip()]);
        json_fail('That file could not be uploaded.', 'VALIDATION', 400);
    }

    /* ---------- what IS it? Read the bytes, not the headers ---------- */
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        json_fail('That file is not an image we can read. Use a JPG, PNG or WebP.', 'VALIDATION', 422);
    }

    $ext = upload_type_extension((int) $info[2]);
    if ($ext === null) {
        json_fail('Images must be JPG, PNG or WebP.', 'VALIDATION', 422);
    }

    // A 30000×30000 PNG is a few hundred KB on disk and gigabytes once
    // decoded, so cap the dimensions too, not just the byte size.
    if ((int) $info[0] > 6000 || (int) $info[1] > 6000) {
        json_fail('That image is too large in dimensions (6000×6000 max).', 'VALIDATION', 422);
    }

    /* ---------- store it under a name we invented ---------- */
    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0775, true) && !is_dir(UPLOAD_DIR)) {
        app_log('Upload directory missing and could not be created', ['dir' => UPLOAD_DIR]);
        json_fail('The server could not save the file. Please try again.', 'SERVER', 500);
    }

    // Random name: no part of the client's filename survives, so neither a
    // path traversal ('../../x') nor a double extension ('a.php.jpg') can.
    $safePrefix = preg_match('/^[a-z0-9-]{1,20}$/', $prefix) ? $prefix : 'img';
    $name       = $safePrefix . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
    $target     = UPLOAD_DIR . DIRECTORY_SEPARATOR . $name;

    if (!@move_uploaded_file($file['tmp_name'], $target)) {
        app_log('move_uploaded_file failed', ['target' => $target]);
        json_fail('The server could not save the file. Please try again.', 'SERVER', 500);
    }

    @chmod($target, 0644);

    return UPLOAD_URL . '/' . $name;
}

function delete_stored_upload(?string $url): void
{
    if ($url === null || $url === '') {
        return;
    }
    if (!preg_match('#^' . preg_quote(UPLOAD_URL, '#') . '/([A-Za-z0-9._-]+)$#', $url, $m)) {
        return;                       // not ours, or contains a path separator
    }

    $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $m[1];
    // realpath() resolves any '..' before we compare, so the file must truly
    // sit inside the uploads folder.
    $real = realpath($path);
    $root = realpath(UPLOAD_DIR);

    if ($real !== false && $root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
        @unlink($real);
    }
}

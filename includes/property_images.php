<?php
function store_property_image(array $upload): ?string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return 'default.jpg';
    }

    if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !isset($upload['tmp_name'], $upload['size']) || !is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('The image upload did not complete. Please select the image again.');
    }

    if ((int) $upload['size'] <= 0 || (int) $upload['size'] > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Property images must be smaller than 5 MB.');
    }

    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($upload['tmp_name']);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mimeType]) || getimagesize($upload['tmp_name']) === false) {
        throw new InvalidArgumentException('Upload a valid JPG, PNG, or WebP property image.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    $imageDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images';
    if (!is_dir($imageDirectory) && !mkdir($imageDirectory, 0755, true) && !is_dir($imageDirectory)) {
        throw new RuntimeException('The property image directory could not be created.');
    }

    $destination = $imageDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        throw new RuntimeException('The property image could not be saved.');
    }

    return $filename;
}

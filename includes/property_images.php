<?php
function property_category_image(string $propertyType): ?string
{
    $categoryImages = [
        'Residential' => 'residential-property-01.jpg',
        'Commercial' => 'commercial-property-01.jpg',
        'Farmlot' => 'farm-property-01.jpg',
        'Beachlot' => 'beach-property-01.jpg',
        'Condo' => 'condo-property-01.jpg',
        'House and Lot' => 'residential-property-02.jpg',
        'Townhouse' => 'residential-property-04.jpg',
        'Apartment' => 'condo-property-03.jpg',
        'Warehouse' => 'commercial-property-03.jpg',
        'Office Space' => 'commercial-property-04.jpg',
        'Resort' => 'beach-property-04.jpg',
    ];

    return $categoryImages[$propertyType] ?? null;
}

function get_property_image_credit(string $filename): ?array
{
    static $credits = [
        'beach-property-01.jpg' => [
            'title' => 'Wonga Beach, Qld, Australia',
            'creator' => 'jemasmith',
            'sourceUrl' => 'https://www.flickr.com/photos/26085795@N02/5151233404',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'beach-property-02.jpg' => [
            'title' => 'Beach',
            'creator' => 'Corey Leopold',
            'sourceUrl' => 'https://www.flickr.com/photos/97708873@N00/180337697',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'beach-property-03.jpg' => [
            'title' => 'Limestone above the Beach',
            'creator' => 'trishhartmann',
            'sourceUrl' => 'https://www.flickr.com/photos/21078769@N00/12015008074',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'beach-property-04.jpg' => [
            'title' => 'Moorea Island, French Polynesia',
            'creator' => 'dany13',
            'sourceUrl' => 'https://www.flickr.com/photos/11493299@N00/8044046451',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'beach-property-05.jpg' => [
            'title' => 'PEI Beach - Basin Head',
            'creator' => 'Bold Frontiers',
            'sourceUrl' => 'https://www.flickr.com/photos/82955120@N05/7731186444',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'beach-property-06.jpg' => [
            'title' => 'Shade On The Beach',
            'creator' => 'kenteegardin',
            'sourceUrl' => 'https://www.flickr.com/photos/26373139@N08/6669041987',
            'license' => 'CC BY-SA 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/2.0/',
        ],
        'beach-property-07.jpg' => [
            'title' => 'Dominican-Republic - Bayahibe',
            'creator' => 'Traveller-Reini',
            'sourceUrl' => 'https://www.flickr.com/photos/129472585@N03/16274828284',
            'license' => 'CC BY-SA 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/2.0/',
        ],
        'farm-property-01.jpg' => [
            'title' => "The Nation's Salad Bowl",
            'creator' => 'Malcolm Carlaw',
            'sourceUrl' => 'https://www.flickr.com/photos/48865807@N06/16712216257',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'farm-property-02.jpg' => [
            'title' => 'Sakura Rice Fields',
            'creator' => 'Follow Your Nose',
            'sourceUrl' => 'https://www.flickr.com/photos/31816015@N02/4845499708',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'farm-property-03.jpg' => [
            'title' => 'Recently sown paddy fields in Sindh, Pakistan',
            'creator' => 'DFID - UK Department for International Development',
            'sourceUrl' => 'https://www.flickr.com/photos/14214150@N02/5331068220',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'farm-property-04.jpg' => [
            'title' => 'Rice field terrace',
            'creator' => 'Jo@net',
            'sourceUrl' => 'https://www.flickr.com/photos/15411972@N00/5797060215',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'farm-property-05.jpg' => [
            'title' => 'Simple Landscape',
            'creator' => 'DavidMartynHunt',
            'sourceUrl' => 'https://www.flickr.com/photos/35395389@N00/1306006614',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'condo-property-01.jpg' => [
            'title' => 'Edifício Copan',
            'creator' => 'Rodrigo_Soldon',
            'sourceUrl' => 'https://www.flickr.com/photos/8865243@N02/3407218439',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'condo-property-02.jpg' => [
            'title' => 'Changi Rise, Singapore',
            'creator' => 'alantankenghoe',
            'sourceUrl' => 'https://www.flickr.com/photos/57785759@N06/5468009204',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'condo-property-03.jpg' => [
            'title' => 'Apartment Building in Havana',
            'creator' => 'vxla',
            'sourceUrl' => 'https://www.flickr.com/photos/14812197@N00/9712701695',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'condo-property-04.jpg' => [
            'title' => 'Stamford Gardens, Milverton',
            'creator' => 'lydia_shiningbrightly',
            'sourceUrl' => 'https://www.flickr.com/photos/31917220@N07/3017010538',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'condo-property-05.jpg' => [
            'title' => 'Hugo Häring, Siemensstadt Housing, 1929',
            'creator' => 'seier+seier',
            'sourceUrl' => 'https://www.flickr.com/photos/94852245@N00/2565057817',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'residential-property-01.jpg' => [
            'title' => 'Three gables, 10 windows, blue shutters',
            'creator' => 'Wonderlane',
            'sourceUrl' => 'https://www.flickr.com/photos/71401718@N00/4670406821',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'residential-property-02.jpg' => [
            'title' => "Won't you be my neighbor?",
            'creator' => 'Design By Zouny',
            'sourceUrl' => 'https://www.flickr.com/photos/47440581@N07/4349026082',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'residential-property-03.jpg' => [
            'title' => 'Gray house, Dutch style dual gables',
            'creator' => 'Wonderlane',
            'sourceUrl' => 'https://www.flickr.com/photos/71401718@N00/4670417601',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'residential-property-04.jpg' => [
            'title' => 'Tacoma',
            'creator' => 'Hugo-90',
            'sourceUrl' => 'https://www.flickr.com/photos/32109282@N00/3341771067',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'residential-property-05.jpg' => [
            'title' => 'Las Colinas, aerial',
            'creator' => 'La Citta Vita',
            'sourceUrl' => 'https://www.flickr.com/photos/49539505@N04/6039810461',
            'license' => 'CC BY-SA 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/2.0/',
        ],
        'residential-property-06.jpg' => [
            'title' => 'York Cottage and the lower lake at Sandringham',
            'creator' => 'ell brown',
            'sourceUrl' => 'https://www.flickr.com/photos/39415781@N06/4574794418',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'residential-property-07.jpg' => [
            'title' => 'House near NMU',
            'creator' => 'mrkumm',
            'sourceUrl' => 'https://www.flickr.com/photos/73319630@N00/3716836335',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'commercial-property-01.jpg' => [
            'title' => 'Green Roof, Vancouver BC',
            'creator' => 'NNECAPA',
            'sourceUrl' => 'https://www.flickr.com/photos/23163410@N02/2830785109',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'commercial-property-02.jpg' => [
            'title' => 'Matarazzo Building (Sao Paulo City Hall)',
            'creator' => 'Diego3336',
            'sourceUrl' => 'https://www.flickr.com/photos/31018257@N00/10879504754',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'commercial-property-03.jpg' => [
            'title' => 'Cognizant Pune Delivery Center',
            'creator' => 'Cognizant Technology Solutions',
            'sourceUrl' => 'https://www.flickr.com/photos/43262583@N05/3986486156',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'commercial-property-04.jpg' => [
            'title' => 'Federal Building',
            'creator' => 'Dano',
            'sourceUrl' => 'https://www.flickr.com/photos/36101697408@N01/409202019',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'commercial-property-05.jpg' => [
            'title' => 'Glass Office Building',
            'creator' => 'ricardodiaz11',
            'sourceUrl' => 'https://www.flickr.com/photos/41652235@N00/4485128485',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'commercial-property-06.jpg' => [
            'title' => 'Office Buildings',
            'creator' => 'oatsy40',
            'sourceUrl' => 'https://www.flickr.com/photos/68089229@N06/9569863543',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'commercial-property-07.jpg' => [
            'title' => 'Night Illuminated Office Building',
            'creator' => 'OiMax',
            'sourceUrl' => 'https://www.flickr.com/photos/72396314@N00/37254613004',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
    ];

    return $credits[$filename] ?? null;
}

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

/**
 * Normalizes multi-file upload arrays from $_FILES['galleryImages'] into a list of single upload arrays.
 *
 * @param array $fileInput
 * @return array
 */
function normalize_uploaded_files(array $fileInput): array
{
    $files = [];
    if (!isset($fileInput['name'])) {
        return $files;
    }

    if (is_array($fileInput['name'])) {
        $count = count($fileInput['name']);
        for ($i = 0; $i < $count; $i++) {
            if (($fileInput['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => $fileInput['name'][$i] ?? '',
                'type' => $fileInput['type'][$i] ?? '',
                'tmp_name' => $fileInput['tmp_name'][$i] ?? '',
                'error' => $fileInput['error'][$i] ?? UPLOAD_ERR_OK,
                'size' => $fileInput['size'][$i] ?? 0,
            ];
        }
    } elseif (($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $files[] = $fileInput;
    }

    return $files;
}

/**
 * Stores multiple uploaded property images and returns their saved filenames.
 *
 * @param array $fileInput
 * @return array Array of saved filenames
 */
function store_property_images_batch(array $fileInput): array
{
    $normalized = normalize_uploaded_files($fileInput);
    $saved = [];
    foreach ($normalized as $upload) {
        $filename = store_property_image($upload);
        if ($filename !== null && $filename !== 'default.jpg') {
            $saved[] = $filename;
        }
    }
    return $saved;
}

/**
 * Retrieves all gallery images for a property ordered by primary status and sort order.
 *
 * @param mysqli $conn
 * @param int $propertyId
 * @return array
 */
function get_property_gallery_images(mysqli $conn, int $propertyId): array
{
    if ($propertyId <= 0) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT imageID, propertyID, imagePath, caption, sortOrder, isPrimary, createdAt
         FROM property_images
         WHERE propertyID = ?
         ORDER BY isPrimary DESC, sortOrder ASC, imageID ASC"
    );
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $propertyId);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * Inserts a gallery image for a property.
 *
 * @param mysqli $conn
 * @param int $propertyId
 * @param string $filename
 * @param string|null $caption
 * @param int $sortOrder
 * @param bool $isPrimary
 * @return int Inserted imageID
 */
function add_property_gallery_image(
    mysqli $conn,
    int $propertyId,
    string $filename,
    ?string $caption = null,
    int $sortOrder = 0,
    bool $isPrimary = false
): int {
    $stmt = $conn->prepare(
        "INSERT INTO property_images (propertyID, imagePath, caption, sortOrder, isPrimary)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return 0;
    }
    $primaryInt = $isPrimary ? 1 : 0;
    $stmt->bind_param('issii', $propertyId, $filename, $caption, $sortOrder, $primaryInt);
    $stmt->execute();
    return (int) $conn->insert_id;
}

/**
 * Deletes a gallery image and removes the file if it is an uploaded file.
 *
 * @param mysqli $conn
 * @param int $imageId
 * @param int|null $propertyId
 * @return bool
 */
function delete_property_gallery_image(mysqli $conn, int $imageId, ?int $propertyId = null): bool
{
    if ($imageId <= 0) {
        return false;
    }

    $query = "SELECT imagePath, propertyID FROM property_images WHERE imageID = ?";
    if ($propertyId !== null && $propertyId > 0) {
        $query .= " AND propertyID = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('ii', $imageId, $propertyId);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $imageId);
    }

    $stmt->execute();
    $imageRow = $stmt->get_result()->fetch_assoc();
    if (!$imageRow) {
        return false;
    }

    $delStmt = $conn->prepare("DELETE FROM property_images WHERE imageID = ?");
    $delStmt->bind_param('i', $imageId);
    $deleted = $delStmt->execute();

    if ($deleted) {
        $path = $imageRow['imagePath'];
        // Category sample images are shared assets and must not be deleted with a gallery entry.
        if (get_property_image_credit($path) === null && $path !== 'default.jpg') {
            $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $path;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    return $deleted;
}

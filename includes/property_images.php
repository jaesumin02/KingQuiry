<?php
function get_property_image_credit(string $filename): ?array
{
    static $credits = [
        'representative-beach-01.jpg' => [
            'title' => 'Wonga Beach, Qld, Australia',
            'creator' => 'jemasmith',
            'sourceUrl' => 'https://www.flickr.com/photos/26085795@N02/5151233404',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-beach-02.jpg' => [
            'title' => 'Beach',
            'creator' => 'Corey Leopold',
            'sourceUrl' => 'https://www.flickr.com/photos/97708873@N00/180337697',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-beach-03.jpg' => [
            'title' => 'Limestone above the Beach',
            'creator' => 'trishhartmann',
            'sourceUrl' => 'https://www.flickr.com/photos/21078769@N00/12015008074',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-beach-04.jpg' => [
            'title' => 'Moorea Island, French Polynesia',
            'creator' => 'dany13',
            'sourceUrl' => 'https://www.flickr.com/photos/11493299@N00/8044046451',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-farm-01.jpg' => [
            'title' => "The Nation's Salad Bowl",
            'creator' => 'Malcolm Carlaw',
            'sourceUrl' => 'https://www.flickr.com/photos/48865807@N06/16712216257',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-farm-02.jpg' => [
            'title' => 'Sakura Rice Fields',
            'creator' => 'Follow Your Nose',
            'sourceUrl' => 'https://www.flickr.com/photos/31816015@N02/4845499708',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-farm-03.jpg' => [
            'title' => 'Recently sown paddy fields in Sindh, Pakistan',
            'creator' => 'DFID - UK Department for International Development',
            'sourceUrl' => 'https://www.flickr.com/photos/14214150@N02/5331068220',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-farm-04.jpg' => [
            'title' => 'Rice field terrace',
            'creator' => 'Jo@net',
            'sourceUrl' => 'https://www.flickr.com/photos/15411972@N00/5797060215',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-condo-01.jpg' => [
            'title' => 'Edifício Copan',
            'creator' => 'Rodrigo_Soldon',
            'sourceUrl' => 'https://www.flickr.com/photos/8865243@N02/3407218439',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-condo-02.jpg' => [
            'title' => 'Changi Rise, Singapore',
            'creator' => 'alantankenghoe',
            'sourceUrl' => 'https://www.flickr.com/photos/57785759@N06/5468009204',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-condo-03.jpg' => [
            'title' => 'Apartment Building in Havana',
            'creator' => 'vxla',
            'sourceUrl' => 'https://www.flickr.com/photos/14812197@N00/9712701695',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-condo-04.jpg' => [
            'title' => 'Stamford Gardens, Milverton',
            'creator' => 'lydia_shiningbrightly',
            'sourceUrl' => 'https://www.flickr.com/photos/31917220@N07/3017010538',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-residential-01.jpg' => [
            'title' => 'Three gables, 10 windows, blue shutters',
            'creator' => 'Wonderlane',
            'sourceUrl' => 'https://www.flickr.com/photos/71401718@N00/4670406821',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-residential-02.jpg' => [
            'title' => "Won't you be my neighbor?",
            'creator' => 'Design By Zouny',
            'sourceUrl' => 'https://www.flickr.com/photos/47440581@N07/4349026082',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-residential-03.jpg' => [
            'title' => 'Gray house, Dutch style dual gables',
            'creator' => 'Wonderlane',
            'sourceUrl' => 'https://www.flickr.com/photos/71401718@N00/4670417601',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-residential-04.jpg' => [
            'title' => 'Tacoma',
            'creator' => 'Hugo-90',
            'sourceUrl' => 'https://www.flickr.com/photos/32109282@N00/3341771067',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-residential-05.jpg' => [
            'title' => 'Las Colinas, aerial',
            'creator' => 'La Citta Vita',
            'sourceUrl' => 'https://www.flickr.com/photos/49539505@N04/6039810461',
            'license' => 'CC BY-SA 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by-sa/2.0/',
        ],
        'representative-commercial-01.jpg' => [
            'title' => 'Green Roof, Vancouver BC',
            'creator' => 'NNECAPA',
            'sourceUrl' => 'https://www.flickr.com/photos/23163410@N02/2830785109',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-commercial-02.jpg' => [
            'title' => 'Matarazzo Building (Sao Paulo City Hall)',
            'creator' => 'Diego3336',
            'sourceUrl' => 'https://www.flickr.com/photos/31018257@N00/10879504754',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-commercial-03.jpg' => [
            'title' => 'Cognizant Pune Delivery Center',
            'creator' => 'Cognizant Technology Solutions',
            'sourceUrl' => 'https://www.flickr.com/photos/43262583@N05/3986486156',
            'license' => 'CC BY 2.0',
            'licenseUrl' => 'https://creativecommons.org/licenses/by/2.0/',
        ],
        'representative-commercial-04.jpg' => [
            'title' => 'Federal Building',
            'creator' => 'Dano',
            'sourceUrl' => 'https://www.flickr.com/photos/36101697408@N01/409202019',
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

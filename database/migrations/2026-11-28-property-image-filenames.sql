-- Apply once to an existing database after renaming assets in assets/images.
-- Fresh installations should import database/schema.sql instead.

UPDATE `properties`
SET `image` = REPLACE(
    REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(`image`, 'representative-beach-', 'beach-property-'),
                'representative-commercial-', 'commercial-property-'
            ),
            'representative-condo-', 'condo-property-'
        ),
        'representative-farm-', 'farm-property-'
    ),
    'representative-residential-', 'residential-property-'
)
WHERE `image` LIKE 'representative-%';

UPDATE `property_images`
SET `imagePath` = REPLACE(
    REPLACE(
        REPLACE(
            REPLACE(
                REPLACE(`imagePath`, 'representative-beach-', 'beach-property-'),
                'representative-commercial-', 'commercial-property-'
            ),
            'representative-condo-', 'condo-property-'
        ),
        'representative-farm-', 'farm-property-'
    ),
    'representative-residential-', 'residential-property-'
)
WHERE `imagePath` LIKE 'representative-%';

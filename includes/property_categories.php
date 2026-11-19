<?php
function property_type_labels(): array
{
    return [
        'Residential' => 'Residential Lot',
        'Commercial' => 'Commercial Property',
        'Farmlot' => 'Farm Lot',
        'Beachlot' => 'Beach Lot',
        'Condo' => 'Condo',
        'House and Lot' => 'House and Lot',
        'Townhouse' => 'Townhouse',
        'Apartment' => 'Apartment',
        'Warehouse' => 'Warehouse',
        'Office Space' => 'Office Space',
        'Resort' => 'Resort',
    ];
}

function property_type_values(): array
{
    return array_keys(property_type_labels());
}

function property_type_label(string $propertyType): string
{
    return property_type_labels()[$propertyType] ?? $propertyType;
}

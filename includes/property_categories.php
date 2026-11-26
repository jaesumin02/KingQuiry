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

function property_type_min_area_sqm(string $propertyType): float
{
    return match ($propertyType) {
        'Condo', 'Apartment', 'Office Space' => 20.0,
        'Townhouse' => 100.0,
        'Residential', 'House and Lot' => 120.0,
        'Commercial', 'Beachlot', 'Warehouse', 'Resort' => 200.0,
        'Farmlot' => 500.0,
        default => 20.0,
    };
}

<?php

namespace App\Filament\Admin\Resources\CourseCategories;

use App\Filament\Admin\Resources\CourseCategories\Pages\CreateCourseCategory;
use App\Filament\Admin\Resources\CourseCategories\Pages\EditCourseCategory;
use App\Filament\Admin\Resources\CourseCategories\Pages\ListCourseCategories;
use App\Filament\Admin\Resources\CourseCategories\Schemas\CourseCategoryForm;
use App\Filament\Admin\Resources\CourseCategories\Tables\CourseCategoriesTable;
use App\Models\CourseCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The academy's shelf, which is not the shop's shelf.
 *
 * Kept apart from the marketplace tree on purpose — see the CourseCategory
 * model. Sharing one would put every new course subject into the catalogue
 * navigation, where it would sell nothing.
 */
class CourseCategoryResource extends Resource
{
    protected static ?string $model = CourseCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Academy';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('Course subjects');
    }

    public static function getModelLabel(): string
    {
        return __('course subject');
    }

    public static function form(Schema $schema): Schema
    {
        return CourseCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CourseCategoriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseCategories::route('/'),
            'create' => CreateCourseCategory::route('/create'),
            'edit' => EditCourseCategory::route('/{record}/edit'),
        ];
    }
}

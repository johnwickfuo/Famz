<?php

namespace App\Filament\Admin\Resources\Courses;

use App\Filament\Admin\Resources\Courses\Pages\CreateCourse;
use App\Filament\Admin\Resources\Courses\Pages\EditCourse;
use App\Filament\Admin\Resources\Courses\Pages\ListCourses;
use App\Filament\Admin\Resources\Courses\RelationManagers\EnrolmentsRelationManager;
use App\Filament\Admin\Resources\Courses\RelationManagers\ModulesRelationManager;
use App\Filament\Admin\Resources\Courses\Schemas\CourseForm;
use App\Filament\Admin\Resources\Courses\Tables\CoursesTable;
use App\Models\Course;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Courses, written by the company.
 *
 * There is no instructor field and no seller scoping here, because there is no
 * third-party authoring in this product. Everything under this resource is the
 * company's own material.
 */
class CourseResource extends Resource
{
    protected static ?string $model = Course::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Academy';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return __('Courses');
    }

    public static function form(Schema $schema): Schema
    {
        return CourseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CoursesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ModulesRelationManager::class,
            EnrolmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourses::route('/'),
            'create' => CreateCourse::route('/create'),
            'edit' => EditCourse::route('/{record}/edit'),
        ];
    }
}

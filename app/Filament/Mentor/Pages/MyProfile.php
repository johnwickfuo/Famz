<?php

namespace App\Filament\Mentor\Pages;

use App\Enums\ContactMethod;
use App\Models\MentorProfile;
use App\Models\Specialisation;
use App\Support\Nigeria;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The mentor's own profile and the tags they are matched on.
 *
 * The specialisations are on this page rather than buried in a settings screen
 * because they are the single thing that decides whether this mentor is ever
 * shortlisted. A mentor who quietly loses their tags stops getting work and has
 * no way of knowing why.
 *
 * @property-read Schema $form
 */
class MyProfile extends Page
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('My profile');
    }

    public function getTitle(): string|Htmlable
    {
        return __('My profile');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('What clients read, and what we match you on.');
    }

    public static function currentMentor(): ?MentorProfile
    {
        return auth()->user()?->mentorProfile;
    }

    public function mount(): void
    {
        $mentor = static::currentMentor();

        abort_if($mentor === null, 403);

        $this->form->fill([
            'headline' => $mentor->headline,
            'bio' => $mentor->bio,
            'strengths' => $mentor->strengths,
            'years_experience' => $mentor->years_experience,
            'qualifications' => $mentor->qualifications,
            'affiliation' => $mentor->affiliation,
            'avatar' => $mentor->avatar,
            'preferred_contact_method' => $mentor->preferred_contact_method->value,
            'contact_value' => $mentor->contact_value,
            'states_served' => $mentor->states_served ?? [],
            'accepts_remote' => $mentor->accepts_remote,
            'accepts_in_person' => $mentor->accepts_in_person,
            'specialisations' => $mentor->specialisations()->pluck('specialisations.id')->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('What clients read'))
                    ->columns(2)
                    ->schema([
                        TextInput::make('headline')
                            ->label(__('One line about you'))
                            ->required()
                            ->maxLength(160)
                            ->columnSpanFull(),

                        Textarea::make('bio')
                            ->label(__('About you'))
                            ->required()
                            ->rows(5)
                            ->maxLength(4000)
                            ->columnSpanFull(),

                        Textarea::make('strengths')
                            ->label(__('What you are good at'))
                            ->required()
                            ->rows(4)
                            ->maxLength(2000)
                            ->helperText(__('In your own words. This is read by people — the tags below are what we match on.'))
                            ->columnSpanFull(),

                        TextInput::make('years_experience')
                            ->label(__('Years doing this'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(80)
                            ->required(),

                        TextInput::make('affiliation')
                            ->label(__('Farm or business'))
                            ->maxLength(255),

                        Textarea::make('qualifications')
                            ->label(__('Qualifications'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),

                        FileUpload::make('avatar')
                            ->label(__('Photograph'))
                            ->image()
                            ->imageEditor()
                            ->avatar()
                            ->maxSize(2048)
                            ->disk('public')
                            ->directory('mentors')
                            ->visibility('public'),
                    ]),

                Section::make(__('What you can help with'))
                    ->description(__('This is what decides whether you appear in somebody\'s shortlist. Keep it honest and keep it current.'))
                    ->schema([
                        CheckboxList::make('specialisations')
                            ->label(__('Your specialisations'))
                            ->options(fn (): array => Specialisation::query()
                                ->active()
                                ->ordered()
                                ->pluck('name', 'id')
                                ->all())
                            ->descriptions(fn (): array => Specialisation::query()
                                ->active()
                                ->ordered()
                                ->pluck('description', 'id')
                                ->filter()
                                ->all())
                            ->columns(2)
                            ->searchable()
                            ->bulkToggleable()
                            ->required()
                            ->minItems(1),
                    ]),

                Section::make(__('How clients reach you'))
                    ->columns(2)
                    ->schema([
                        Select::make('preferred_contact_method')
                            ->label(__('Preferred way'))
                            ->options(ContactMethod::options())
                            ->required()
                            ->live(),

                        TextInput::make('contact_value')
                            ->label(fn (Get $get): string => ContactMethod::tryFrom((string) $get('preferred_contact_method'))
                                ?->valueLabel() ?? __('Contact'))
                            ->required()
                            ->maxLength(255)
                            // Said on the screen where it is typed, not only in
                            // the marketing copy.
                            ->helperText(__('Never shown to anybody until they have paid for an engagement with you.')),

                        Toggle::make('accepts_remote')
                            ->label(__('I can work by phone, WhatsApp or video')),

                        Toggle::make('accepts_in_person')
                            ->label(__('I can visit a farm'))
                            ->live(),

                        Select::make('states_served')
                            ->label(__('States you will travel to'))
                            ->options(array_combine(Nigeria::states(), Nigeria::states()))
                            ->multiple()
                            ->searchable()
                            ->visible(fn (Get $get): bool => (bool) $get('accepts_in_person'))
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $mentor = static::currentMentor();

        abort_if($mentor === null, 403);

        $state = $this->form->getState();

        $mentor->forceFill([
            'headline' => $state['headline'],
            'bio' => $state['bio'],
            'strengths' => $state['strengths'],
            'years_experience' => (int) $state['years_experience'],
            'qualifications' => $state['qualifications'] ?? null,
            'affiliation' => $state['affiliation'] ?? null,
            'avatar' => $state['avatar'] ?? null,
            'preferred_contact_method' => ContactMethod::from($state['preferred_contact_method']),
            'contact_value' => $state['contact_value'],
            'states_served' => array_values($state['states_served'] ?? []),
            'accepts_remote' => (bool) ($state['accepts_remote'] ?? false),
            'accepts_in_person' => (bool) ($state['accepts_in_person'] ?? false),
        ])->save();

        $mentor->specialisations()->sync($state['specialisations'] ?? []);

        Notification::make()->title(__('Profile saved'))->success()->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('Save my profile'))->submit('save'),
                    ])->key('form-actions'),
                ]),
        ]);
    }
}

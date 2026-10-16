<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\CustomFieldValue;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;

final class DuplicateAction extends CreateAction
{
    public static function getDefaultName(): string
    {
        return 'duplicate';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('filament/actions/duplicate.label'))
            ->icon(Heroicon::OutlinedSquare2Stack)
            ->modalHeading(fn (): string => __('filament/actions/duplicate.modal.heading', ['title' => $this->getRecordTitle()]))
            ->modalSubmitActionLabel(__('filament/actions/duplicate.modal.submit'))
            ->createAnother(false)
            ->mountUsing(function (Schema $schema, Model $record, Page|RelationManager $livewire): void {
                $schema->fill([
                    ...$record->attributesToArray(),
                    ...($record instanceof HasCustomFields ? ['custom_fields' => $this->customFieldState($record)] : []),
                ]);
                $schema->model($record)->loadStateFromRelationships(shouldHydrate: true);
                $schema->model($record::class);

                $livewire->mergeMountedActionArguments(['original' => $this->getRawData()]);
            })
            ->before(function (array $arguments, Page|RelationManager $livewire): void {
                if (($arguments['confirmed'] ?? false) || $this->getRawData() !== $arguments['original']) {
                    return;
                }

                $livewire->mountAction('confirmUnchanged');
                $this->halt();
            })
            ->registerModalActions([
                Action::make('confirmUnchanged')
                    ->requiresConfirmation()
                    ->overlayParentActions()
                    ->modalHeading(__('filament/actions/duplicate.unchanged.heading'))
                    ->modalDescription(fn (): string => __('filament/actions/duplicate.unchanged.description', ['label' => $this->getModelLabel()]))
                    ->modalSubmitActionLabel(__('filament/actions/duplicate.unchanged.submit'))
                    ->modalCancelActionLabel(__('filament/actions/duplicate.unchanged.cancel'))
                    ->action(function (Page|RelationManager $livewire): void {
                        $livewire->unmountAction(cancelParentActions: false);
                        $livewire->callMountedAction(['confirmed' => true]);
                    }),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customFieldState(Model&HasCustomFields $record): array
    {
        return $record->customFieldValues()
            ->with('customField')
            ->get()
            ->keyBy('customField.code')
            ->map(fn (CustomFieldValue $value): mixed => $record->getCustomFieldValue($value->customField))
            ->all();
    }
}

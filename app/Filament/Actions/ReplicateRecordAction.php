<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Models\CustomFieldValue;
use Filament\Actions\Action;
use Filament\Actions\ReplicateAction;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;

final class ReplicateRecordAction extends ReplicateAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->excludeAttributes(['creator_id', 'creation_source', 'deleted_at'])
            ->schema(fn (Schema $schema, Page $livewire): Schema => $livewire::getResource()::form($schema))
            ->mountUsing(function (Schema $schema, Model $record, Page $livewire): void {
                $schema->fill([
                    ...$record->attributesToArray(),
                    ...($record instanceof HasCustomFields ? ['custom_fields' => $this->customFieldState($record)] : []),
                ]);
                $schema->model($record)->loadStateFromRelationships(shouldHydrate: true);
                $schema->model($record::class);

                $livewire->mergeMountedActionArguments(['original' => $this->getRawData()]);
            })
            ->before(function (array $arguments, Page $livewire): void {
                if (($arguments['confirmed'] ?? false) || $this->getRawData() !== $arguments['original']) {
                    return;
                }

                $livewire->mountAction('confirmUnchanged');
                $this->halt();
            })
            ->beforeReplicaSaved(fn (Model $replica) => $replica->setRawAttributes([
                ...$replica->getAttributes(),
                ...Arr::only($replica->newInstance()->getAttributes(), $this->getExcludedAttributes() ?? []),
            ]))
            ->after(fn (Model $replica, Page $livewire) => $livewire
                ->getSchema($livewire->getMountedActionSchemaName())
                ?->model($replica)
                ->saveRelationships())
            ->registerModalActions([
                Action::make('confirmUnchanged')
                    ->requiresConfirmation()
                    ->overlayParentActions()
                    ->modalHeading(__('filament/actions/replicate.unchanged.heading'))
                    ->modalDescription(fn (): string => __('filament/actions/replicate.unchanged.description', ['label' => $this->getModelLabel()]))
                    ->modalSubmitActionLabel(__('filament/actions/replicate.unchanged.submit'))
                    ->modalCancelActionLabel(__('filament/actions/replicate.unchanged.cancel'))
                    ->action(function (Page $livewire): void {
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

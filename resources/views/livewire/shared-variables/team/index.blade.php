<div>
    <x-slot:title>
        Team Variables | GridFrames
    </x-slot>

    <x-shared-variables.editor :resource="$team" :variables="$team->environment_variables"
        type="team" title="Team variables" :view="$view" variablesLabel="Team shared variables" />
</div>

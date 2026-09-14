<div>
    <x-slot:title>
        Team Variables | Xlaunch
    </x-slot>

    <x-shared-variables.editor :resource="$team" :variables="$team->environment_variables"
        type="team" title="Team variables" :view="$view" variablesLabel="Team shared variables" />
</div>

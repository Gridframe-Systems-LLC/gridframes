<div>
    <x-slot:title>
        Server Variables | GridFrames
    </x-slot>

    <x-shared-variables.editor :resource="$server"
        :variables="$server->environment_variables"
        :readOnlyKeys="['XLAUNCH_SERVER_UUID', 'XLAUNCH_SERVER_NAME']"
        type="server" title="{{ $server->name }}"
        :view="$view" variablesLabel="Server shared variables" />
</div>

/** Lightweight stubs so mount tests assert behaviour without NC chrome. */
const fieldModel = { prop: 'modelValue', event: 'update:modelValue' }

export const ncStubs = {
	NcSettingsSection: {
		name: 'NcSettingsSection',
		props: ['name', 'description'],
		template: '<section class="stub-settings-section"><h2>{{ name }}</h2><p v-if="description">{{ description }}</p><slot /></section>',
	},
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		model: fieldModel,
		props: ['modelValue', 'checked', 'type'],
		template: '<label class="stub-checkbox"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)" /><slot /></label>',
	},
	NcTextField: {
		name: 'NcTextField',
		model: fieldModel,
		props: ['value', 'modelValue', 'label', 'type', 'placeholder', 'min', 'max'],
		template: '<label class="stub-text-field">{{ label }}<input :type="type || \'text\'" :value="modelValue" :placeholder="placeholder" :min="min" :max="max" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>',
	},
	NcPasswordField: {
		name: 'NcPasswordField',
		model: fieldModel,
		props: ['value', 'modelValue', 'label', 'placeholder', 'autocomplete'],
		template: '<label class="stub-password-field">{{ label }}<input type="password" :value="modelValue" :placeholder="placeholder" :autocomplete="autocomplete" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>',
	},
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'type', 'variant'],
		template: '<button type="button" class="stub-button" :disabled="disabled" @click="$emit(\'click\')"><slot /></button>',
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type', 'heading'],
		template: '<div class="stub-note" :data-type="type" role="status"><strong v-if="heading">{{ heading }}</strong><slot /></div>',
	},
}

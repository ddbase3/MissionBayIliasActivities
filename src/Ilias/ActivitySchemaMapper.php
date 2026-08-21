<?php declare(strict_types=1);

namespace MissionBayIliasActivities\Ilias;

use ILIAS\Data\Description\DList;
use ILIAS\Data\Description\DMap;
use ILIAS\Data\Description\DObject;
use ILIAS\Data\Description\DValue;
use ILIAS\Data\Description\Description;
use ILIAS\Data\Description\ValueType;
use ILIAS\Data\Text\SimpleDocumentMarkdown;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Component\Input\Field\Checkbox;
use ILIAS\UI\Component\Input\Field\Numeric;
use ILIAS\UI\Component\Input\Field\Select;
use ILIAS\UI\Component\Input\Field\Text;
use ILIAS\UI\Component\Input\Field\Textarea;
use ILIAS\UI\Component\Input\Group as InputGroup;

final class ActivitySchemaMapper {

	/** @return array{schema:array<string,mixed>,supported:bool,unsupported:list<string>} */
	public function mapInput(FormInput $input): array {
		$unsupported = [];
		$schema = $this->mapFormInput($input, $unsupported);
		return [
			'schema' => $schema,
			'supported' => $unsupported === [],
			'unsupported' => array_values(array_unique($unsupported))
		];
	}

	/** @return array{schema:array<string,mixed>,supported:bool,unsupported:list<string>} */
	public function mapOutput(Description $description): array {
		$unsupported = [];
		$schema = $this->mapDescription($description, $unsupported);
		return [
			'schema' => $schema,
			'supported' => $unsupported === [],
			'unsupported' => array_values(array_unique($unsupported))
		];
	}

	/** @param list<string> $unsupported @return array<string,mixed> */
	private function mapFormInput(FormInput $input, array &$unsupported): array {
		$schema = [];
		$label = trim($input->getLabel());
		$byline = trim((string)$input->getByline());
		if ($label !== '') $schema['title'] = $label;
		if ($byline !== '') $schema['description'] = $byline;
		if ($input->isDisabled()) $schema['readOnly'] = true;

		if ($input instanceof InputGroup) {
			$properties = [];
			$required = [];
			foreach ($input->getInputs() as $name => $child) {
				if (!$child instanceof FormInput) {
					$unsupported[] = is_object($child) ? get_class($child) : get_debug_type($child);
					continue;
				}
				$properties[(string)$name] = $this->mapFormInput($child, $unsupported);
				if ($child->isRequired()) $required[] = (string)$name;
			}
			$schema['type'] = 'object';
			$schema['properties'] = $properties;
			$schema['required'] = $required;
			$schema['additionalProperties'] = false;
			return $schema;
		}

		if ($input instanceof Numeric) {
			// ILIAS Numeric may represent integer or stepped numeric values.
			// Keep the generic schema broad and let the Activity input processing validate it.
			$schema['type'] = 'number';
			return $schema;
		}
		if ($input instanceof Text || $input instanceof Textarea) {
			$schema['type'] = 'string';
			if ($input instanceof Text && $input->getMaxLength() !== null) {
				$schema['maxLength'] = $input->getMaxLength();
			}
			return $schema;
		}
		if ($input instanceof Select) {
			$schema['type'] = 'string';
			$schema['enum'] = array_map('strval', array_keys($input->getOptions()));
			return $schema;
		}
		if ($input instanceof Checkbox) {
			$schema['type'] = 'boolean';
			return $schema;
		}

		$unsupported[] = get_class($input);
		$schema['x-ilias-input-class'] = get_class($input);
		return $schema;
	}

	/** @param list<string> $unsupported @return array<string,mixed> */
	private function mapDescription(Description $description, array &$unsupported): array {
		$schema = [];
		$text = $this->markdown($description->getDescription());
		if ($text !== '') $schema['description'] = $text;

		if ($description instanceof DObject) {
			$properties = [];
			$required = [];
			foreach ($description->getFields() as $field) {
				$properties[$field->getName()] = $this->mapDescription($field->getType(), $unsupported);
				$required[] = $field->getName();
			}
			$schema['type'] = 'object';
			$schema['properties'] = $properties;
			$schema['required'] = $required;
			$schema['additionalProperties'] = false;
			return $schema;
		}
		if ($description instanceof DList) {
			$schema['type'] = 'array';
			$schema['items'] = $this->mapDescription($description->getValueType(), $unsupported);
			return $schema;
		}
		if ($description instanceof DMap) {
			$schema['type'] = 'object';
			$schema['additionalProperties'] = $this->mapDescription($description->getValueType(), $unsupported);
			$schema['x-ilias-map-key'] = $this->mapDescription($description->getKeyType(), $unsupported);
			return $schema;
		}
		if ($description instanceof DValue) {
			$type = $description->getType();
			$schema['type'] = match($type) {
				ValueType::INT => 'integer',
				ValueType::FLOAT => 'number',
				ValueType::STRING => 'string',
				ValueType::DATETIME => 'string',
				ValueType::BOOL => 'boolean',
				ValueType::NULL => 'null'
			};
			if ($type === ValueType::DATETIME) $schema['format'] = 'date-time';
			return $schema;
		}

		$unsupported[] = get_class($description);
		$schema['x-ilias-description-class'] = get_class($description);
		return $schema;
	}

	private function markdown(?SimpleDocumentMarkdown $text): string {
		return $text?->getRawRepresentation() ?? '';
	}
}

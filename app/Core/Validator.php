<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Validation centralisée côté serveur.
 * Règles : required, email, min:n, max:n, int, in:a,b, same:champ, date, phone.
 */
final class Validator
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $rules ex. ['email' => 'required|email|max:190']
     * @param array<string,string> $labels libellés français des champs
     * @return array<string,mixed> données validées (chaînes nettoyées)
     * @throws ValidationException
     */
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        $clean = [];
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $label = $labels[$field] ?? $field;
            $ruleList = explode('|', $ruleString);
            $empty = $value === null || $value === '' || $value === [];
            if ($empty) {
                if (in_array('required', $ruleList, true)) {
                    $errors[$field] = "Le champ « $label » est obligatoire.";
                }
                $clean[$field] = null;
                continue;
            }
            foreach ($ruleList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = self::check($name, $arg, $value, $data, $label);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
            $clean[$field] = $value;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    /** @param array<string,mixed> $data */
    private static function check(string $name, ?string $arg, mixed $value, array $data, string $label): ?string
    {
        if (!is_scalar($value)) {
            return "Le champ « $label » est invalide.";
        }
        $str = (string) $value;
        return match ($name) {
            'required' => null,
            'email' => filter_var($str, FILTER_VALIDATE_EMAIL) ? null : "« $label » doit être une adresse email valide.",
            'min' => mb_strlen($str) >= (int) $arg ? null : "« $label » doit contenir au moins $arg caractères.",
            'max' => mb_strlen($str) <= (int) $arg ? null : "« $label » ne doit pas dépasser $arg caractères.",
            'int' => filter_var($str, FILTER_VALIDATE_INT) !== false ? null : "« $label » doit être un nombre entier.",
            'in' => in_array($str, explode(',', (string) $arg), true) ? null : "« $label » contient une valeur non autorisée.",
            'same' => $str === (string) ($data[$arg] ?? '') ? null : "« $label » ne correspond pas.",
            'date' => \DateTimeImmutable::createFromFormat('Y-m-d', $str) !== false ? null : "« $label » doit être une date valide.",
            'phone' => preg_match('/^[0-9 +().-]{6,30}$/', $str) ? null : "« $label » doit être un numéro de téléphone valide.",
            'slug' => preg_match('/^[a-z0-9_]{2,50}$/', $str) ? null : "« $label » : lettres minuscules, chiffres et _ uniquement.",
            default => throw new \LogicException("Règle de validation inconnue : $name"),
        };
    }
}

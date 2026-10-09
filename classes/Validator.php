<?php
declare(strict_types=1);

/** Input rules shared by the account, recipe, and comment pages. */
final class Validator
{
    public function validateRegistration(string $name, string $email, string $password, string $confirmation): array
    {
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $errors[] = 'Name must contain 2 to 100 characters.';
        }
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address (maximum 255 characters).';
        }
        // The default PHP password_hash algorithm may be bcrypt on PHP 8.0; keep its 72-byte limit in mind.
        if (strlen($password) < 8 || strlen($password) > 72) {
            $errors[] = 'Password must be 8 to 72 bytes long.';
        }
        if ($password !== $confirmation) {
            $errors[] = 'Passwords do not match.';
        }
        return $errors;
    }

    public function validateLogin(string $email, string $password): array
    {
        $errors = [];
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Enter a valid email address.';
        }
        if ($password === '') {
            $errors[] = 'Password is required.';
        }
        return $errors;
    }

    /** @return array{errors: array<int,string>, ingredients: array<int,array{name:string,quantity:string,unit:string}>} */
    public function validateRecipe(
        string $title,
        string $description,
        int $categoryId,
        string $steps,
        array $rawIngredients,
        bool $categoryExists
    ): array {
        $errors = [];
        $ingredients = [];

        if ($title === '' || mb_strlen($title) > 150) {
            $errors[] = 'Recipe title is required and must be at most 150 characters.';
        }
        if ($description === '' || mb_strlen($description) > 500) {
            $errors[] = 'Description is required and must be at most 500 characters.';
        }
        if ($categoryId < 1 || !$categoryExists) {
            $errors[] = 'Choose a valid recipe category.';
        }
        if ($steps === '' || mb_strlen($steps) > 20000) {
            $errors[] = 'Cooking steps are required and must be at most 20,000 characters.';
        }
        if (count($rawIngredients) > 40) {
            $errors[] = 'A recipe can have at most 40 ingredients.';
        }

        foreach (array_slice($rawIngredients, 0, 40) as $index => $row) {
            if (!is_array($row)) {
                $errors[] = 'One of the ingredient entries is invalid.';
                continue;
            }
            $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
            $quantity = isset($row['quantity']) && is_string($row['quantity']) ? trim($row['quantity']) : '';
            $unit = isset($row['unit']) && is_string($row['unit']) ? trim($row['unit']) : '';

            if ($name === '' && $quantity === '' && $unit === '') {
                continue; // Ignore a wholly blank dynamic ingredient row.
            }
            if ($name === '') {
                $errors[] = 'Ingredient ' . ((int) $index + 1) . ' needs a name.';
                continue;
            }
            if (mb_strlen($name) > 100 || mb_strlen($quantity) > 20 || mb_strlen($unit) > 30) {
                $errors[] = 'Ingredient ' . ((int) $index + 1) . ' has a value that is too long.';
                continue;
            }
            $ingredients[] = ['name' => $name, 'quantity' => $quantity, 'unit' => $unit];
        }

        if ($ingredients === []) {
            $errors[] = 'Add at least one ingredient with a name.';
        }

        return ['errors' => $errors, 'ingredients' => $ingredients];
    }

    public function validateComment(string $body): array
    {
        if ($body === '' || mb_strlen($body) > 1000) {
            return ['Comment is required and must be at most 1,000 characters.'];
        }
        return [];
    }
}

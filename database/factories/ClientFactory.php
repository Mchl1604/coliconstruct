<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * A residential client with nobody's account behind them yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'client_type' => 'Residential',
            'company_name' => null,
            'surname' => $last,
            'firstname' => $first,
            'middlename' => null,
            'fullname' => $first.' '.$last,
            // Unique in tbl_clients, so the sequence keeps every fixture apart.
            'email_address' => fake()->unique()->safeEmail(),
            'contact_number' => '09'.fake()->numerify('#########'),
        ];
    }

    /**
     * A company, named on the record alongside its contact person.
     */
    public function commercial(?string $companyName = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'client_type' => 'Commercial',
            'company_name' => $companyName ?? fake()->company(),
        ]);
    }
}

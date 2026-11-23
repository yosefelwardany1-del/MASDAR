<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\EmployeeSalary;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Attachments\AttachmentService;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of employees, employee_salaries and employee_documents (spec §5.7). The code
 * is numbered once and never changes (trigger); salaries and documents are append-only, so a
 * raise, a correction or a renewal is a new row. Salaries need payroll.edit: they are seen only
 * by whoever sees payroll.
 */
class EmployeeService
{
    public const FIELDS = ['name', 'name_local', 'user_id', 'job_title', 'department', 'work_location', 'employment_type', 'nationality',
        'birth_date', 'phone', 'email', 'address', 'emergency_contact', 'id_number', 'bank_details', 'salary_currency', 'joined_on',
        'left_on', 'notes'];

    /** Fields seen only by those who edit the employee file or see payroll. */
    public const PRIVATE_FIELDS = ['id_number', 'bank_details', 'birth_date', 'address'];

    public function __construct(private NumberingService $numbers, private AttachmentService $attachments) {}

    /** @param array<string, mixed> $data FIELDS */
    public function create(array $data, User $by): Employee
    {
        $this->authorize($by, 'employees.edit');
        $data = $this->checked($data, null);

        return DB::transaction(function () use ($data) {
            $employee = Employee::create($data + ['code' => $this->numbers->next('employee')]);
            AuditTrail::record('created', $employee, [], $this->audited($employee));

            return $employee;
        });
    }

    /** @param array<string, mixed> $data FIELDS */
    public function update(Employee $employee, array $data, User $by): Employee
    {
        $this->authorize($by, 'employees.edit');

        return DB::transaction(function () use ($employee, $data) {
            $employee = Employee::query()->lockForUpdate()->findOrFail($employee->id);
            $data = $this->checked($data, $employee);
            $old = $this->audited($employee);
            $employee->update($data);
            AuditTrail::record('updated', $employee, $old, $this->audited($employee));

            return $employee;
        });
    }

    /**
     * A salary from a date: base and fixed monthly allowances. A raise or a correction is a new
     * row; the latest on or before a date is the salary of that date.
     *
     * @param  array{effective_from?: string, base_salary?: string, allowances?: string, currency?: string, note?: string}  $data
     */
    public function addSalary(Employee $employee, array $data, User $by): EmployeeSalary
    {
        $this->authorize($by, 'payroll.edit');
        $from = $this->date($data['effective_from'] ?? null, 'effective_from', required: true);
        $base = Money::amount($data['base_salary'] ?? null, 'base_salary', positive: true, decimals: 2);
        $allowances = Money::amount($data['allowances'] ?? null, 'allowances', decimals: 2);
        $currency = strtoupper((string) ($data['currency'] ?? '') ?: $employee->salary_currency);
        if (! array_key_exists($currency, Lookup::options('currency'))) {
            throw ValidationException::withMessages(['currency' => __('Choose a currency from the list of currencies.')]);
        }
        if ($from < $employee->joined_on->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => __('A salary starts on or after the joining date (:date).', ['date' => $employee->joined_on->toDateString()])]);
        }
        // B28: salaries are a history; a second one from the same day is said, not taken silently.
        if (EmployeeSalary::query()->where('employee_id', $employee->id)->whereDate('effective_from', $from)->exists()) {
            throw ValidationException::withMessages(['effective_from' => __('A salary from :date is already recorded. Salaries are kept as a history: give the new one the date it starts.', ['date' => $from])]);
        }

        return DB::transaction(function () use ($employee, $from, $base, $allowances, $currency, $data, $by) {
            $salary = EmployeeSalary::create([
                'employee_id' => $employee->id, 'effective_from' => $from, 'base_salary' => $base, 'allowances' => $allowances,
                'currency' => $currency, 'note' => $this->text($data['note'] ?? null), 'created_by' => $by->id,
            ]);
            AuditTrail::record('salary_added', $employee, [], [
                'effective_from' => $from, 'base_salary' => $base, 'allowances' => $allowances, 'currency' => $currency,
            ]);

            return $salary;
        });
    }

    /**
     * A document (passport, residence permit, contract …) with its scans. A renewal is a new
     * row of the same kind; the newest is current.
     *
     * @param  array{kind?: string, number?: string, issued_on?: string, expires_on?: string, note?: string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function addDocument(Employee $employee, array $data, User $by, array $files = []): EmployeeDocument
    {
        $this->authorize($by, 'employees.edit');
        $kind = (string) ($data['kind'] ?? '');
        if (! array_key_exists($kind, Lookup::options('employee_document'))) {
            throw ValidationException::withMessages(['kind' => __('Choose the kind of document.')]);
        }
        $issued = $this->date($data['issued_on'] ?? null, 'issued_on');
        $expires = $this->date($data['expires_on'] ?? null, 'expires_on');
        if ($issued !== null && $expires !== null && $expires < $issued) {
            throw ValidationException::withMessages(['expires_on' => __('It cannot expire before it was issued.')]);
        }

        return DB::transaction(function () use ($employee, $kind, $issued, $expires, $data, $by, $files) {
            $document = EmployeeDocument::create([
                'employee_id' => $employee->id, 'kind' => $kind, 'number' => $this->text($data['number'] ?? null, 80),
                'issued_on' => $issued, 'expires_on' => $expires, 'note' => $this->text($data['note'] ?? null), 'created_by' => $by->id,
            ]);
            $title = Lookup::label('employee_document', $kind).($document->number ? ' '.$document->number : '');
            foreach ($files as $file) {
                $this->attachments->store($employee, $file, $by->id, $title);
            }
            AuditTrail::record('document_added', $employee, [], ['kind' => $kind, 'number' => $document->number, 'expires_on' => $expires]);

            return $document;
        });
    }

    /** The rules every write keeps, whoever calls: the screen's validation only shapes the input. */
    private function checked(array $data, ?Employee $employee): array
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field])) {
                $data[$field] = trim($data[$field]) === '' ? null : trim($data[$field]);
            }
        }
        $errors = [];
        if (($data['name'] ?? $employee?->name) === null) {
            $errors['name'] = __('Enter the employee\'s name.');
        }
        foreach (['department' => 'department', 'work_location' => 'work_location', 'employment_type' => 'employment_type', 'nationality' => 'country'] as $field => $list) {
            $code = $data[$field] ?? null;
            if ($code !== null && $code !== $employee?->{$field} && ! array_key_exists($code, Lookup::options($list))) {
                $errors[$field] = __('Choose from the list.');
            }
        }
        if (array_key_exists('salary_currency', $data) || $employee === null) {
            $data['salary_currency'] = strtoupper((string) ($data['salary_currency'] ?? '') ?: 'CNY');
            if ($data['salary_currency'] !== $employee?->salary_currency && ! array_key_exists($data['salary_currency'], Lookup::options('currency'))) {
                $errors['salary_currency'] = __('Choose a currency from the list of currencies.');
            }
        }
        if (($data['email'] ?? null) !== null && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = __('Enter a valid e-mail address.');
        }
        foreach (['birth_date', 'joined_on', 'left_on'] as $field) {
            if (array_key_exists($field, $data)) {
                try {
                    $data[$field] = $this->date($data[$field], $field, required: $field === 'joined_on');
                } catch (ValidationException $e) {
                    $errors += $e->errors();
                }
            }
        }
        // B28: nobody is born in the future.
        if (($data['birth_date'] ?? null) !== null && ! isset($errors['birth_date']) && $data['birth_date'] > now()->toDateString()) {
            $errors['birth_date'] = __('The birth date cannot be in the future.');
        }
        $joined = $data['joined_on'] ?? $employee?->joined_on?->toDateString();
        if ($joined === null && ! isset($errors['joined_on'])) {
            $errors['joined_on'] = __('Enter the joining date.');
        }
        $left = array_key_exists('left_on', $data) ? $data['left_on'] : $employee?->left_on?->toDateString();
        if ($joined !== null && $left !== null && $left < $joined) {
            $errors['left_on'] = __('The leaving date cannot be before the joining date.');
        }
        if (array_key_exists('user_id', $data) && $data['user_id'] !== null) {
            $data['user_id'] = (int) $data['user_id'];
            if (! User::query()->whereKey($data['user_id'])->exists()) {
                $errors['user_id'] = __('That user does not exist.');
            } elseif (Employee::query()->where('user_id', $data['user_id'])->when($employee, fn ($q) => $q->whereKeyNot($employee->id))->exists()) {
                $errors['user_id'] = __('That user is already linked to another employee.');
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function date(mixed $value, string $field, bool $required = false): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => __('Enter a date.')]);
            }

            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', (string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('Enter a date.')]);
        }
    }

    private function text(mixed $value, int $max = 2000): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function audited(Employee $employee): array
    {
        // The audit log is read by system administrators: private fields are logged as changed, not by value.
        $row = $employee->only(array_merge(['code'], array_diff(self::FIELDS, self::PRIVATE_FIELDS)));
        foreach (self::PRIVATE_FIELDS as $field) {
            // A fingerprint shows that it changed without showing it.
            $value = $employee->{$field} instanceof \DateTimeInterface ? $employee->{$field}->format('Y-m-d') : $employee->{$field};
            $row[$field] = $value === null ? null : '(hidden '.substr(hash('sha256', (string) $value), 0, 8).')';
        }
        $row['joined_on'] = $employee->joined_on?->toDateString();
        $row['left_on'] = $employee->left_on?->toDateString();

        return $row;
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
    }
}

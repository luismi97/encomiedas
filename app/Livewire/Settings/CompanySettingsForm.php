<?php

namespace App\Livewire\Settings;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class CompanySettingsForm extends Component
{
    use WithFileUploads;

    public bool $enabled = false;
    public string $environment = 'sandbox';
    public string $name = '';
    public string $commercial_name = '';
    public string $identification_type = '02';
    public string $identification_number = '';
    public string $activity_code = '';
    public string $province = '';
    public string $canton = '';
    public string $district = '';
    public string $barrio = '';
    public string $others_signs = '';
    public string $phone = '';
    public string $email = '';
    public string $atv_username = '';
    public string $atv_password = '';
    public string $certificate_pin = '';
    public string $default_cabys_code = '';

    /** @var mixed */
    public $certificate;

    public bool $hasCertificate = false;

    public function mount(): void
    {
        $settings = CompanySetting::instance();

        $this->enabled = $settings->enabled;
        $this->environment = $settings->environment;
        $this->name = (string) $settings->name;
        $this->commercial_name = (string) $settings->commercial_name;
        $this->identification_type = $settings->identification_type ?: '02';
        $this->identification_number = (string) $settings->identification_number;
        $this->activity_code = (string) $settings->activity_code;
        $this->province = (string) $settings->province;
        $this->canton = (string) $settings->canton;
        $this->district = (string) $settings->district;
        $this->barrio = (string) $settings->barrio;
        $this->others_signs = (string) $settings->others_signs;
        $this->phone = (string) $settings->phone;
        $this->email = (string) $settings->email;
        $this->atv_username = (string) $settings->atv_username;
        $this->default_cabys_code = (string) $settings->default_cabys_code;
        $this->hasCertificate = filled($settings->certificate_path);
    }

    protected function rules(): array
    {
        return [
            'environment' => 'required|in:sandbox,prod',
            'name' => 'required|string|max:150',
            'commercial_name' => 'nullable|string|max:100',
            'identification_type' => 'required|in:01,02,03,04',
            'identification_number' => 'required|string|max:20',
            'activity_code' => 'nullable|string|max:6',
            'province' => 'nullable|string|max:1',
            'canton' => 'nullable|string|max:2',
            'district' => 'nullable|string|max:2',
            'barrio' => 'nullable|string|max:100',
            'others_signs' => 'nullable|string|max:250',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email',
            'atv_username' => 'nullable|string|max:150',
            'atv_password' => 'nullable|string|max:150',
            'certificate' => 'nullable|file|max:2048',
            'certificate_pin' => 'nullable|string|max:50',
            'default_cabys_code' => 'nullable|string|max:13',
        ];
    }

    public function save(): void
    {
        $data = $this->validate();

        $settings = CompanySetting::instance();

        if ($this->certificate) {
            $path = $this->certificate->storeAs('certificados', $settings->id . '.p12', 'hacienda');
            $settings->certificate_path = $path;
            $this->hasCertificate = true;
        }

        $settings->fill([
            'enabled' => $this->enabled,
            'environment' => $data['environment'],
            'name' => $data['name'],
            'commercial_name' => $data['commercial_name'],
            'identification_type' => $data['identification_type'],
            'identification_number' => $data['identification_number'],
            'activity_code' => $data['activity_code'],
            'province' => $data['province'],
            'canton' => $data['canton'],
            'district' => $data['district'],
            'barrio' => $data['barrio'],
            'others_signs' => $data['others_signs'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'default_cabys_code' => $data['default_cabys_code'],
        ]);

        if (filled($this->atv_username)) {
            $settings->atv_username = $this->atv_username;
        }
        if (filled($this->atv_password)) {
            $settings->atv_password = $this->atv_password;
        }
        if (filled($this->certificate_pin)) {
            $settings->certificate_pin = $this->certificate_pin;
        }

        $settings->save();

        $this->certificate = null;
        $this->atv_password = '';
        $this->certificate_pin = '';

        session()->flash('success', 'Configuración de la empresa guardada.');
    }

    public function render()
    {
        return view('livewire.settings.company-settings-form')
            ->layout('layouts.app', ['title' => 'Configuración de la empresa']);
    }
}

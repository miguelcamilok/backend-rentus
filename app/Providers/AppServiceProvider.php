<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

use App\Models\User;
use App\Models\Property;
use App\Models\Contract;
use App\Models\Payment;

use App\Observers\UserObserver;
use App\Observers\PropertyObserver;
use App\Observers\ContractObserver;
use App\Observers\PaymentObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        User::observe(UserObserver::class);
        Property::observe(PropertyObserver::class);
        Contract::observe(ContractObserver::class);
        Payment::observe(PaymentObserver::class);
    }
}

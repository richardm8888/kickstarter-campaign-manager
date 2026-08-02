<?php

namespace App\Integrations\Contracts;

use App\Integrations\SyncResult;

/**
 * Every third-party integration implements this contract.
 * API calls for a provider live only inside its implementation —
 * never scattered through controllers, jobs or services.
 */
interface Integration
{
    /** Machine name, e.g. "meta", "ga4", "mailerlite", "stripe". */
    public function provider(): string;

    /** Human name shown in the connect wizard, e.g. "Meta Ads". */
    public function displayName(): string;

    /** Credential fields the connect wizard must collect. */
    public function requiredCredentials(): array;

    /** Validate and store credentials, marking the integration connected. */
    public function connect(array $credentials): void;

    /** Remove credentials and mark the integration disconnected. */
    public function disconnect(): void;

    /** Pull fresh data from the provider into append-only metric snapshots. */
    public function sync(): SyncResult;

    /** Current connection status for the wizard and health checks. */
    public function status(): array;
}

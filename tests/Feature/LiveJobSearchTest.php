<?php

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $customer = Customer::create(['name' => 'Live Search Customer', 'phone' => '0779988776']);
    $this->bikes = collect(['BAM 546', 'BAM-1234', 'BIY-3310'])->map(fn ($number) => $customer->bikes()->create(['number' => $number, 'model' => 'Dio']));
    $this->makeJob = function ($bike, $number, $status = 'Received') {
        $intake = BikeIntake::create(['intake_number' => 'IN-'.$number, 'bike_id' => $bike->id, 'complaint' => 'Search test', 'created_by' => auth()->id()]);

        return JobCard::create(['number' => $number, 'bike_intake_id' => $intake->id, 'status' => $status, 'service_total' => 1000, 'created_by' => auth()->id()]);
    };
    $this->firstJob = ($this->makeJob)($this->bikes[0], 'JOB-BAM-RECEIVED');
    $this->billedJob = ($this->makeJob)($this->bikes[0], 'JOB-BAM-BILLED', 'Billed');
    ($this->makeJob)($this->bikes[1], 'JOB-BAM-OTHER');
    $this->quick = JobCard::create(['number' => 'JOB-BIY-QUICK', 'is_quick_job' => true, 'quick_bike_id' => $this->bikes[2]->id, 'quick_customer_id' => $customer->id, 'status' => 'Received', 'created_by' => auth()->id()]);
    Invoice::create(['number' => 'INV-LIVE-SEARCH', 'invoice_type' => 'Job Card Bill', 'job_card_id' => $this->billedJob->id, 'customer_id' => $customer->id, 'bike_id' => $this->bikes[0]->id, 'subtotal' => 1000, 'total' => 1000, 'due_amount' => 1000, 'status' => 'Unpaid', 'public_token' => 'live-search-invoice', 'created_by' => auth()->id()]);
});

test('each partial bike query filters the actual job table and keeps payment actions', function () {
    $fixtures = [];
    foreach (['' => 4, 'b' => 4, 'ba' => 3, 'bam' => 3, 'bam5' => 2, 'BAM-5' => 2, 'BAM 5' => 2, '33' => 1, 'zzzz' => 0] as $query => $total) {
        $response = $this->getJson(route('jobs.index', ['bike_search' => $query]))->assertOk()->assertJsonPath('total', $total)->assertHeader('Cache-Control', 'no-store, private');
        $html = $response->json('html');
        expect($html)->not->toContain('<html', 'Workshop navigation');
        if ($query === 'bam5') {
            expect($html)->toContain('JOB-BAM-RECEIVED', 'JOB-BAM-BILLED', 'openDuePaymentModal(this)', 'data-job-id="'.$this->billedJob->id.'"')->not->toContain('JOB-BAM-OTHER', 'JOB-BIY-QUICK');
        }
        if ($query === 'zzzz') {
            expect($html)->toContain('No jobs found.');
        }
        $fixtures[] = ['query' => preg_replace('/[^a-z0-9]/i', '', strtolower($query)), 'bike_id' => '', 'status' => '', 'data' => $response->json()];
    }
    foreach (['Received' => 1, 'Completed' => 1] as $status => $total) {
        $response = $this->getJson(route('jobs.index', ['bike_search' => 'bam5', 'status' => $status]))->assertOk()->assertJsonPath('total', $total);
        $fixtures[] = ['query' => 'bam5', 'bike_id' => '', 'status' => $status, 'data' => $response->json()];
    }
    foreach ($this->bikes as $bike) {
        $response = $this->getJson(route('jobs.index', ['bike_id' => $bike->id]))->assertOk();
        $fixtures[] = ['query' => '', 'bike_id' => (string) $bike->id, 'status' => '', 'data' => $response->json()];
    }
    if ($directory = getenv('LIVE_JOB_QA_DIR')) {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($directory.'/jobs.html', $this->get(route('jobs.index'))->assertOk()->getContent());
        file_put_contents($directory.'/responses.json', json_encode($fixtures));
    }
});

test('typed bike search combines with status and overrides an older selected bike', function () {
    $response = $this->getJson(route('jobs.index', ['bike_search' => 'bam 5', 'bike_id' => $this->bikes[2]->id, 'status' => 'Completed']))->assertOk()->assertJsonPath('total', 1);
    expect($response->json('html'))->toContain('JOB-BAM-BILLED')->not->toContain('JOB-BAM-RECEIVED', 'JOB-BIY-QUICK');
    $this->getJson(route('jobs.index', ['bike_search' => '33']))->assertOk()->assertJsonPath('total', 1);
    $this->get(route('jobs.index', ['bike_search' => 'bam5']))->assertOk()->assertViewHas('jobs', fn ($jobs) => $jobs->total() === 2)
        ->assertSee('name="bike_search" value="bam5"', false)->assertSee('data-live-job-filter', false)->assertSeeText('Clear filters');
});

test('live search queries every page and keeps its filters in pagination links', function () {
    for ($i = 0; $i < 17; $i++) {
        ($this->makeJob)($this->bikes[0], 'JOB-LIVE-PAGE-'.$i, 'Billed');
    }
    $response = $this->getJson(route('jobs.index', ['bike_search' => 'bam5', 'status' => 'Completed']))->assertOk()->assertJsonPath('total', 18);
    expect($response->json('html'))->toContain('bike_search=bam5', 'status=Completed', 'page=2');
    $page = $this->getJson(route('jobs.index', ['bike_search' => 'bam5', 'status' => 'Completed', 'page' => 2]))->assertOk()->assertJsonPath('total', 18);
    expect($page->json('html'))->toContain('JOB-BAM-BILLED')->not->toContain('JOB-BIY-QUICK');
});

test('legacy quick-job registration snapshots can be searched without a bike id', function () {
    JobCard::create(['number' => 'JOB-LEGACY-SEARCH', 'is_quick_job' => true, 'quick_bike_details' => ['number' => 'XXY 0777', 'model' => 'Dio'], 'status' => 'Received', 'created_by' => auth()->id()]);
    $response = $this->getJson(route('jobs.index', ['bike_search' => 'xxy-07']))->assertOk()->assertJsonPath('total', 1);
    expect($response->json('html'))->toContain('JOB-LEGACY-SEARCH', 'XXY 0777');
    $this->getJson(route('jobs.index', ['bike_search' => str_repeat('A', 61)]))->assertUnprocessable()->assertJsonValidationErrors('bike_search');
});

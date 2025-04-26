<?php
    header('Content-Type: application/json');
    setlocale(LC_TIME, 'tr_TR.UTF-8');
    date_default_timezone_set('Europe/Istanbul');

    // Ay ve yıl parametrelerini al
    $month = isset($_GET['month']) ? intval($_GET['month']) : date('n');
    $year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $vtc_id = isset($_GET['vtc_id']) ? intval($_GET['vtc_id']) : 57627;

    // Takvim bilgileri
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $first_day_of_month = date('w', mktime(0, 0, 0, $month, 1, $year));

    // Etkinlik verileri
    $url_events_attending = "https://api.truckersmp.com/v2/vtc/{$vtc_id}/events/attending";
    $response_events_attending = @file_get_contents($url_events_attending);
    $data_events_attending = $response_events_attending ? json_decode($response_events_attending, true) : ['response' => []];
    $attending_events = $data_events_attending['response'] ?? [];

    // Gelecekteki etkinlikler
    $future_events = [];
    $current_time = time();
    foreach ($attending_events as $event) {
        $event_start_time = strtotime($event['start_at']);
        if ($event_start_time > $current_time) {
            $future_events[] = $event;
        }
    }

    // Etkinlikleri tarihlere göre gruplandırma
    $events_by_date = [];
    foreach ($future_events as $event) {
        $event_date = date('j', strtotime($event['start_at']));
        $event_month = date('n', strtotime($event['start_at']));
        $event_year = date('Y', strtotime($event['start_at']));
        if ($event_month == $month && $event_year == $year) {
            $events_by_date[$event_date][] = $event;
        }
    }

    // Yanıt
    echo json_encode([
        'days_in_month' => $days_in_month,
        'first_day' => $first_day_of_month,
        'events_by_date' => $events_by_date,
        'future_events' => $future_events
    ]);
?>
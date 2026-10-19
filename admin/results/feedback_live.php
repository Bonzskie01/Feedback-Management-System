<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/admin_layout.php';

if (!is_admin_logged_in()) {
    if (isset($_GET['data'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Your session has expired.']);
        exit;
    }
    redirect('../../index.php');
}

function feedback_live_payload($conn, int $campaignId): array
{
    $campaignResult = pg_query_params($conn, 'SELECT id, title, description, status, start_at, end_at FROM feedback_campaigns WHERE id = $1', [$campaignId]);
    if (!$campaignResult || pg_num_rows($campaignResult) !== 1) return ['error' => 'Campaign not found.'];
    $campaign = pg_fetch_assoc($campaignResult);
    $participantResult = pg_query_params($conn, 'SELECT COUNT(*) FROM feedback_participants WHERE campaign_id = $1', [$campaignId]);
    $responseResult = pg_query_params($conn, 'SELECT COUNT(*) FROM feedback_submissions WHERE campaign_id = $1', [$campaignId]);
    $participants = $participantResult ? (int) pg_fetch_result($participantResult, 0, 0) : 0;
    $responses = $responseResult ? (int) pg_fetch_result($responseResult, 0, 0) : 0;
    $questionResult = pg_query_params($conn, 'SELECT id, prompt, question_type, options, is_required FROM feedback_questions WHERE campaign_id = $1 ORDER BY display_order, id', [$campaignId]);
    $questions = [];

    if ($questionResult) while ($question = pg_fetch_assoc($questionResult)) {
        $questionId = (int) $question['id'];
        $item = ['id' => $questionId, 'prompt' => $question['prompt'], 'type' => $question['question_type'], 'required' => $question['is_required'] === 't'];
        if ($question['question_type'] === 'rating') {
            $ratingResult = pg_query_params($conn, 'SELECT rating_value, COUNT(*) AS total FROM feedback_answers WHERE question_id = $1 AND rating_value IS NOT NULL GROUP BY rating_value ORDER BY rating_value', [$questionId]);
            $distribution = array_fill(1, 5, 0); $ratingTotal = 0; $ratingSum = 0;
            if ($ratingResult) while ($rating = pg_fetch_assoc($ratingResult)) {
                $value = (int) $rating['rating_value']; $count = (int) $rating['total'];
                $distribution[$value] = $count; $ratingTotal += $count; $ratingSum += $value * $count;
            }
            $item['average'] = $ratingTotal ? round($ratingSum / $ratingTotal, 2) : null;
            $item['answer_count'] = $ratingTotal;
            $item['distribution'] = $distribution;
        } elseif (in_array($question['question_type'], ['single_choice', 'multiple_choice'], true)) {
            $item['choices'] = [];
            foreach (json_decode($question['options'], true) ?: [] as $option) {
                $countResult = pg_query_params($conn, 'SELECT COUNT(*) FROM feedback_answers WHERE question_id = $1 AND selected_options ? $2', [$questionId, (string) $option]);
                $count = $countResult ? (int) pg_fetch_result($countResult, 0, 0) : 0;
                $item['choices'][] = ['label' => (string) $option, 'count' => $count, 'percent' => $responses ? round(($count / $responses) * 100, 1) : 0];
            }
        } else {
            $commentResult = pg_query_params($conn, "SELECT a.text_value, s.reference_code, s.submitted_at FROM feedback_answers a JOIN feedback_submissions s ON s.id = a.submission_id WHERE a.question_id = $1 AND NULLIF(trim(a.text_value), '') IS NOT NULL ORDER BY s.submitted_at DESC LIMIT 8", [$questionId]);
            $item['comments'] = [];
            if ($commentResult) while ($comment = pg_fetch_assoc($commentResult)) {
                $item['comments'][] = ['text' => $comment['text_value'], 'reference' => $comment['reference_code'], 'submitted_at' => date('M j, Y g:i A', strtotime($comment['submitted_at']))];
            }
        }
        $questions[] = $item;
    }

    return [
        'campaign' => $campaign,
        'stats' => ['responses' => $responses, 'participants' => $participants, 'rate' => $participants ? round(($responses / $participants) * 100, 1) : 0, 'questions' => count($questions)],
        'questions' => $questions,
        'updated_at' => date(DATE_ATOM),
    ];
}

$campaignId = (int) ($_GET['campaign_id'] ?? 0);
if ($campaignId <= 0) {
    $autoResult = pg_query($conn, "SELECT id FROM feedback_campaigns ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, created_at DESC LIMIT 1");
    if ($autoResult && pg_num_rows($autoResult) === 1) $campaignId = (int) pg_fetch_result($autoResult, 0, 0);
}

if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode($campaignId > 0 ? feedback_live_payload($conn, $campaignId) : ['error' => 'No feedback campaigns are available.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$campaigns = pg_query($conn, "SELECT id, title, status FROM feedback_campaigns ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, created_at DESC");
$initialPayload = $campaignId > 0 ? feedback_live_payload($conn, $campaignId) : ['error' => 'No feedback campaigns are available.'];
admin_page_start('Live Results', 'live');
?>
<div class="live-results-head">
    <div><div class="live-indicator"><span></span> LIVE FEEDBACK</div><h2>Real-time campaign results</h2><p>Response totals and answer summaries refresh automatically every five seconds.</p></div>
    <div class="live-updated"><i class="fa-solid fa-rotate"></i> <span id="lastUpdated">Connecting…</span></div>
</div>
<div class="card live-controls"><form method="get" class="form-grid"><div class="field full"><label for="campaignPicker">Campaign</label><select id="campaignPicker" name="campaign_id" onchange="this.form.submit()">
<?php if (!$campaigns || pg_num_rows($campaigns) === 0): ?><option value="">No campaigns available</option><?php else: while ($campaign = pg_fetch_assoc($campaigns)): ?><option value="<?= (int) $campaign['id'] ?>" <?= $campaignId === (int) $campaign['id'] ? 'selected' : '' ?>><?= h($campaign['title']) ?> (<?= h($campaign['status']) ?>)</option><?php endwhile; endif; ?>
</select></div></form></div>
<div id="liveError" class="alert error" hidden></div>
<section id="liveResults" aria-live="polite"><div class="stats-grid" id="liveStats"></div><div id="liveQuestions"></div></section>
<script>
const campaignId = <?= (int) $campaignId ?>;
let liveData = <?= json_encode($initialPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
let liveRequest = null;
const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
const percent = value => Math.max(0, Math.min(100, Number(value) || 0));
function statCard(icon, className, value, label){return `<div class="stat-card ${className}"><div class="stat-icon"><i class="fa-solid ${icon}"></i></div><div><div class="stat-number">${escapeHtml(value)}</div><div class="stat-label">${escapeHtml(label)}</div></div></div>`}
function renderQuestion(q){let body='';if(q.type==='rating'){body=`<div class="live-rating-summary"><div class="metric">${q.average===null?'—':escapeHtml(q.average)}<small> / 5</small></div><div class="help">${q.answer_count} rating responses</div></div><div class="live-bars">`;for(let score=5;score>=1;score--){const count=Number(q.distribution?.[score]||0),share=q.answer_count?(count/q.answer_count)*100:0;body+=`<div class="live-bar-row"><strong>${score}</strong><i class="fa-solid fa-star"></i><div class="progress"><span style="width:${percent(share)}%"></span></div><span>${count}</span></div>`}body+='</div>'}else if(q.type==='single_choice'||q.type==='multiple_choice'){body='<div class="live-bars">'+q.choices.map(c=>`<div class="live-choice"><div><strong>${escapeHtml(c.label)}</strong><span>${c.count} · ${c.percent}%</span></div><div class="progress"><span style="width:${percent(c.percent)}%"></span></div></div>`).join('')+'</div>'}else{body=q.comments.length?`<div class="live-comments">${q.comments.map(c=>`<article><p>${escapeHtml(c.text)}</p><small>${escapeHtml(c.reference)} · ${escapeHtml(c.submitted_at)}</small></article>`).join('')}</div>`:'<div class="empty">No written responses yet.</div>'}return `<article class="card live-question"><div class="live-question-title"><div><span class="badge">${escapeHtml(q.type.replaceAll('_',' '))}</span><h3>${escapeHtml(q.prompt)}</h3></div>${q.required?'<span class="required-mark">Required</span>':''}</div>${body}</article>`}
function render(data){const error=document.getElementById('liveError');if(data.error){error.textContent=data.error;error.hidden=false;document.getElementById('liveStats').innerHTML='';document.getElementById('liveQuestions').innerHTML='';return}error.hidden=true;const s=data.stats;document.getElementById('liveStats').innerHTML=statCard('fa-message','votes',s.responses,'Responses received')+statCard('fa-users','voters',s.participants,'Participants')+statCard('fa-percent','turnout',`${s.rate}%`,'Response rate')+statCard('fa-circle-question','positions',s.questions,'Questions');document.getElementById('liveQuestions').innerHTML=data.questions.length?data.questions.map(renderQuestion).join(''):'<div class="card empty-state"><i class="fa-solid fa-circle-question"></i><p>No questions have been added to this campaign.</p></div>';document.getElementById('lastUpdated').textContent=`Updated ${new Date(data.updated_at).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'})}`}
async function refreshLiveResults(){if(!campaignId||document.hidden||liveRequest)return;const controller=new AbortController();liveRequest=controller;try{const response=await fetch(`live.php?data=1&campaign_id=${campaignId}`,{headers:{Accept:'application/json'},cache:'no-store',signal:controller.signal});const data=await response.json();if(!response.ok)throw new Error(data.error||'Unable to refresh live results.');render(data)}catch(error){if(error.name!=='AbortError')document.getElementById('lastUpdated').textContent='Refresh paused — retrying'}finally{liveRequest=null}}
render(liveData);const liveTimer=setInterval(refreshLiveResults,5000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refreshLiveResults()});window.addEventListener('beforeunload',()=>{clearInterval(liveTimer);if(liveRequest)liveRequest.abort()});
</script>
<?php admin_page_end(); ?>

<?

include_once '../../../commonlib/phplib/mapit.php';

function api_getConstituency_front() {
?>
<p><big>Fetch a constituency.</big></p>

<h4>Arguments</h4>
<dl>
<dt>postcode</dt>
<dd>Fetch the constituency with associated information for a given postcode.</dd>
<dt>future (optional)</dt>
<dd>If set to anything, return the name of the constituency this postcode will be in
at the next election (<a href="/boundaries/new-constituencies.tsv">list as TSV file</a>,
<a href="/boundaries/cons-ids.tsv">TSV list matching TheyWorkForYou name to Guardian name,
PA ID, and Guardian ID</a>).
This is a temporary feature before the 2010 general election.</dd>
</dl>

<h4>Example Response</h4>
<pre>{ "name" : "Manchester, Gorton" }</pre>

<h4>Example of future variable</h4>
<p>Without future=1, NN12 8NF returns: <samp>{ "name" : "Daventry" }</samp>
<p>With future=1, NN12 8NF returns: <samp>{ "name" : "South Northamptonshire" }</samp>

<?
}

function api_getconstituency_postcode($pc) {
    $pc = preg_replace('#[^a-z0-9 ]#i', '', $pc);

    if (!validate_postcode($pc)) {
        api_error('Invalid postcode');
        return;
    }

    if (get_http_var('future')) {

        $xml = simplexml_load_string(file_get_contents(POSTCODE_API_URL . urlencode($pc)));
        if (!$xml || $xml->error) {
            api_error('Unknown postcode, or problem with lookup');
            return;
        }
        $output['name'] = iconv('utf-8', 'iso-8859-1//TRANSLIT', (string)$xml->future_constituency);
        api_output($output);

    } else {

        $constituency = postcode_to_constituency($pc);
        if ($constituency == 'CONNECTION_TIMED_OUT') {
            api_error('Connection timed out');
            return;
        }
        if (!$constituency) {
            api_error('Unknown postcode');
            return;
        }
        $db = new ParlDB;
        $q = $db->query("select constituency, data_key, data_value from consinfo
                         where constituency = '" . mysql_real_escape_string($constituency) . "'");
        if ($q->rows()) {
            for ($i=0; $i<$q->rows(); $i++) {
                $data_key = $q->field($i, 'data_key');
                $output[$data_key] = $q->field($i, 'data_value');
            }
            ksort($output);
        }
        $output['name'] = $constituency;
        api_output($output);

    }
}


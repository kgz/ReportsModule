<?php

namespace Modules\ReportsModule\Http\Controllers;

use App\Conversation;
use App\Customer;
use App\SendLog;
use Carbon\Carbon;

use App\Thread;
use App\User;
use DateTime;
use DateTimeZone;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Request;
use Illuminate\View\View;
use Modules\FlowJiraModule\Entities\Calls;
use Modules\FlowJiraModule\Entities\Settings;

class ReportsModuleController extends Controller
{
	/**
	 * Display a listing of the resource.
	 *
	 * @return Factory|Application|View
	 */

	public function index()
	{

		$conversations = Conversation::where('status', '!=', Conversation::STATUS_SPAM)->get();
		// only return time since last reply
		$conversations = array_map(function ($conv) {

			return [
				$conv['last_reply_at']
			];
		}, $conversations->toArray());
		return view('ReportsModule::index', [
			'conversations' => json_encode($conversations),
		]);
	}

	public function last_responded()
	{

		$conversations = Conversation::join('users', 'users.id', '=', 'conversations.user_id')
		->where('conversations.status', '!=', Conversation::STATUS_SPAM)
		->where('conversations.state', '!=', 3)
		->get();
		// ->where('conversations.status', '!=', Conversation::STATUS_SPAM);


		// only return time since last reply
		$conversations = array_map(function ($conv) {

			return [
				$conv['last_reply_at'],
				$conv['first_name'] . ' ' . $conv['last_name'],
			];
		}, $conversations->toArray());

		return Response::json($conversations);
	}


	

	public function response_times()
	{
		$filters = json_decode($_GET['filters']);
		// $start = "2017-01-01 00:00:00";
		// $end = "2023-01-31 23:59:59";
		$start = Carbon::createFromTimestamp(round(((int)$filters->start)/1000, 0))->timestamp;
		$end = Carbon::createFromTimestamp(round(((int)$filters->end)/1000, 0))->timestamp;
		// $start = Carbon::parse($start)->timestamp;
		// $end = Carbon::parse($end)->timestamp;
		// // 1677330708459
		// // 1677398399
		// var_dump(Carbon::parse("2023-02-25 23:59:59")->timestamp);

		$messages = Thread::join('conversations', 'conversations.id', '=', 'threads.conversation_id')
			->join('users', 'users.id', '=', 'conversations.user_id')
			->join('customers', 'customers.id', '=', 'conversations.customer_id')
			->select(
				'*',
				'threads.type as ttype',
				'threads.created_at as tcreated_at',
				'threads.conversation_id as conversation_id',
				'customers.first_name as customer_first_name',
				'customers.last_name as customer_last_name',
				'customers.id as customer_id',
				'users.first_name as user_first_name',
				'users.last_name as user_last_name',
			)
			->where('threads.created_at', '>=', Carbon::createFromTimestamp($start)->toDateTimeString())
			->where('threads.created_at', '<=', Carbon::createFromTimestamp($end)->toDateTimeString());
			// where threadid = 20
			// ->where('threads.conversation_id', '=', 21)
			// ->get();
			
			// var_dump($_GET);
			if (isset($filters->customer_ids) && count($filters->customer_ids) > 0) {
				$customer_ids = $filters->customer_ids;
				
				$messages = $messages->whereIn('customers.id', $customer_ids);
			}
			$messages= $messages->groupBy('threads.id');
			$messages = $messages->get();
			

			// ->orderBy('conversations.created_at', 'asc')
			
			// group by conversation id "id" => [array of messages]
			// foreach conversation id
			$nmessages = [];
			foreach ($messages as $message) {
				$type = $message->ttype;
				$nmessages[$message->conversation_id][$type][] = $message;
			}

			// foreach conversation id -> type find the closest message created_at in type 2 or null if none
			foreach ($nmessages as $conversation_id => $conversation) {
				// make sure there are keys 1 and 2
				if (!array_key_exists(1, $conversation) || !array_key_exists(2, $conversation)) {
					continue;
				}
				$messages = $conversation[1];
				$staff_messages = $conversation[2];
	
				foreach ($messages as &$message) {
					$create_dt = Carbon::parse($message->tcreated_at);
					$closest = null;
					$closest_diff = null;
					foreach ($staff_messages as $staff_message) {
						$screate_dt = Carbon::parse($staff_message->tcreated_at);
						// if created at is greater than message created at then skip
						if ($create_dt->gt($screate_dt)) {
							continue;
						}
						$diff = $create_dt->diffInMinutes($screate_dt);
						if ($closest_diff === null || $diff < $closest_diff) {
							$closest_diff = $diff;
							$closest = $staff_message;
							
						}
					}
					if($closest === null) {
						// no closest
						continue;
					}
					$message->response_at = $closest->tcreated_at;
					$message->responder = $closest->user_first_name . ' ' . $closest->user_last_name;
					$message->closest = $closest;
					$message->closest_diff = $closest_diff;
					// diff in hours
					$message->closest_diff_hours = $closest_diff / 60;
				}
			}

			$out = [];
			// return only thread id, thread_conversation id type = 1, responder and response_at
			foreach ($nmessages as $conversation_id => $conversation) {
				if( !array_key_exists(1, $conversation) ) {
					continue;
				}
				$messages = $conversation[1];
				foreach ($messages as $message) {
					if(!isset($message->response_at)) {
						continue;
					}
					$created_at = Carbon::parse($message->tcreated_at);
					$response_at = Carbon::parse($message->response_at);



					$out[] = (object)[
						'type' => 'Business Hours',
						'calculated_duration' => $this->calculate_duration($created_at, $response_at),
						'conversation_created_at_timestamp' => ((int)$created_at->timestamp) * 1000,
						'responder' => $message->responder,
						'response_at' =>  ((int)$response_at->timestamp) * 1000,
						'conversation_id' => $message->conversation_id,
						// adddebug starta nd end
						'start' => $created_at->toDateTimeString(),
						'end' => $response_at->toDateTimeString(),
						'customer_id' => $message->customer_id,
						'customer_first_name' => $message->customer_first_name,
						'customer_last_name' => $message->customer_last_name,
						
					];

					$out[] = (object)[
						'type' => 'Normal',
						'calculated_duration' => $created_at->diffInMinutes($response_at) / 60,
						'conversation_created_at_timestamp' => ((int)$created_at->timestamp) * 1000,
						'responder' => $message->responder,
						'response_at' =>  ((int)$response_at->timestamp) * 1000,
						'conversation_id' => $message->conversation_id,
						'start' => $created_at->toDateTimeString(),
						'end' => $response_at->toDateTimeString(),
						'customer_id' => $message->customer_id,
						'customer_first_name' => $message->customer_first_name,
						'customer_last_name' => $message->customer_last_name,
					];
				}
			}


		
		// return Response::json($nmessages);
		return Response::json($out);
	}
	
	public function outstanding_resposes(){
		// $todo = Conversation::where('conversations.status', '=', 1)->orWhere('conversations.status', '=', 2)
		// 	->where('conversations.created_at', '>=', Carbon::now()->subDays(30))
		// 	->where('conversations.created_at', '<=', Carbon::now())
		// 	->join('customers', 'customers.id', '=', 'conversations.customer_id')
		// 	->join('users', 'users.id', '=', 'conversations.user_id')
		// 	->select(
		// 		'conversations.id as conversation_id',
		// 		'conversations.created_at',
		// 		'conversations.customer_id',
		// 		'customers.first_name as customer_first_name',
		// 		'customers.last_name as customer_last_name',
		// 		'users.first_name',
		// 		'users.last_name',
		// 		'users.id as user_id',
		// 		'conversations.last_reply_at',
		// 		'conversations.status',
		// 	)
		// 	->get();
		// get threads, group by conversation id, get last reply, if ttpe = 1 customer is waiting, if type = 2 agent is waiting
		$threads = Thread::join('conversations', 'conversations.id', '=', 'threads.conversation_id')
			->join('customers', 'customers.id', '=', 'conversations.customer_id')
			->join('users', 'users.id', '=', 'conversations.user_id')
			->distinct('threads.conversation_id')
			->select(
				'conversations.id as conversation_id',
				'conversations.created_at',
				'conversations.customer_id',
				'customers.first_name as customer_first_name',
				'customers.last_name as customer_last_name',
				'users.first_name',
				'users.last_name',
				'users.id as user_id',
				'conversations.last_reply_at',
				'conversations.status',
				'threads.created_at as tcreated_at',
				'threads.type',
			)
			->where('conversations.status', '!=', Conversation::STATUS_SPAM)
			->where('conversations.state', '!=', 3)
			->groupBy('threads.conversation_id')
			// limit to one of lates
			->orderBy('threads.created_at', 'desc')
			->get();

		// calculate wait time for each conversation based on last_reply
		foreach ($threads as $conversation) {
			$conversation->wait_time = $conversation->created_at->diffInHours(Carbon::now());
		}

		// filterout conversations that are not waiting for a response
		$threads = $threads->filter(function ($conversation) {
			return $conversation->status == 1;
		});
		// to array
		$threads = $threads->toArray();
		// to actual array without numbers
		$threads = array_values($threads);

		return Response::json($threads);
	}

	public function getCustomers(){
		$customers = Customer::all();
		return Response::json($customers);

	}


	static function calculate_duration(Carbon $start, Carbon $end): float {
		if ($start->greaterThan($end)) {
			$temp = $start;
			$start = $end;
			$end = $temp;
		}
	
		$duration = 0;
		$day = $start->copy()->startOfDay();
		while ($day->lessThanOrEqualTo($end)) {
			if ($day->isWeekday()) {
				$day_start = $day->copy()->setTime(8, 0, 0);
				$day_end = $day->copy()->setTime(17, 0, 0);
				$day_start = max($start, $day_start);
				$day_end = min($end, $day_end);
				if ($day_start->lessThanOrEqualTo($day_end)) {
					$duration += $day_start->diffInMinutes($day_end);
				}
			}
			$day = $day->addDay();
		}
	
		return $duration / 60.0;
	}

	/**
	 * Boot the application events.
	 *
	 * @return void
	 */
	public function boot()
	{
		$this->registerConfig();
		$this->registerViews();
		// $this->registerCommands();
		$this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
		$this->hooks();
		// $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'FlowJiraModule');

		// \Eventy::addAction('conversation.after_prev_convs', function ($customer, $conversation, $mailbox) {


		// 	echo \View::make('FlowJiraModule::partials/sidebar')->render();
		// }, -1, 3);
	}



	/**
	 * Register config.
	 *
	 * @return void
	 */
	protected function registerConfig()
	{
		$this->publishes([
			__DIR__ . '/../Config/config.php' => config_path('reportsmodule.php'),
		], 'config');
		$this->mergeConfigFrom(
			__DIR__ . '/../Config/config.php',
			'reportsmodule'
		);
	}
}

<?php
/**
 * @copyright Copyright (c) 2017 Joas Schilling <coding@schilljs.com>
 *
 * @author Joas Schilling <coding@schilljs.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Spreed\Migration;

use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Types\Type;
use OCA\Spreed\Participant;
use OCA\Spreed\Room;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\SimpleMigrationStep;
use OCP\Migration\IOutput;

class Version2001Date20170707115443 extends SimpleMigrationStep {

	/** @var IDBConnection */
	protected $db;

	/** @var IConfig */
	protected $config;

	/**
	 * @param IDBConnection $db
	 * @param IConfig $config
	 */
	public function __construct(IDBConnection $db, IConfig $config) {
		$this->db = $db;
		$this->config = $config;
	}

	/**
	 * @param IOutput $output
	 * @param \Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 * @since 13.0.0
	 */
	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$table = $schema->getTable('spreedme_room_participants');

		if (!$table->hasColumn('participantType')) {
			$table->addColumn('participantType', Type::SMALLINT, [
				'notnull' => true,
				'length' => 6,
				'default' => 0,
			]);
		}

		return $schema;
	}

	/**
	 * @param IOutput $output
	 * @param \Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @since 13.0.0
	 */
	public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options) {

		if (version_compare($this->config->getAppValue('spreed', 'installed_version', '0.0.0'), '2.0.0', '<')) {
			// Migrations only work after 2.0.0
			return;
		}

		$query = $this->db->getQueryBuilder();

		$query->selectAlias($query->createFunction('COUNT(*)'), 'num_rooms')
			->from('spreedme_rooms');
		$result = $query->execute();
		$return = (int) $result->fetch();
		$result->closeCursor();
		$numRooms = (int) $return['num_rooms'];

		if ($numRooms === 0) {
			return;
		}

		$query->select('id')
			->from('spreedme_rooms')
			->where($query->expr()->eq('type', $query->createNamedParameter(Room::ONE_TO_ONE_CALL)));
		$result = $query->execute();

		$one2oneRooms = [];
		while ($row = $result->fetch()) {
			$one2oneRooms[] = (int) $row['id'];
		}
		$result->closeCursor();

		if (!empty($one2oneRooms)) {
			$owners = $this->makeOne2OneParticipantsOwners($one2oneRooms);
			$output->info('Made ' . $owners . ' users owner of their one2one calls');
		}

		if (count($one2oneRooms) !== $numRooms) {
			$moderators = $this->makeGroupParticipantsModerators($one2oneRooms);
			$output->info('Made ' . $moderators . ' users moderators in group calls');
		}
	}

	/**
	 * @param int[] $one2oneRooms List of one2one room ids
	 * @return int Number of updated participants
	 */
	protected function makeOne2OneParticipantsOwners(array $one2oneRooms) {
		$update = $this->db->getQueryBuilder();

		if (!$this->db->getDatabasePlatform() instanceof PostgreSqlPlatform) {
			$update->update('spreedme_room_participants')
				->set('participantType', $update->createNamedParameter(Participant::OWNER))
				->where($update->expr()->in('roomId', $update->createNamedParameter($one2oneRooms, IQueryBuilder::PARAM_INT_ARRAY)));
		} else {
			$update->update('spreedme_room_participants')
				->set('participanttype', $update->createNamedParameter(Participant::OWNER))
				->where($update->expr()->in('roomId', $update->createNamedParameter($one2oneRooms, IQueryBuilder::PARAM_INT_ARRAY)));
		}

		return $update->execute();
	}

	/**
	 * @param int[] $one2oneRooms List of one2one room ids which should not be touched
	 * @return int Number of updated participants
	 */
	protected function makeGroupParticipantsModerators(array $one2oneRooms) {
		$update = $this->db->getQueryBuilder();

		if (!$this->db->getDatabasePlatform() instanceof PostgreSqlPlatform) {
			$update->update('spreedme_room_participants')
				->set('participantType', $update->createNamedParameter(Participant::MODERATOR))
				->where($update->expr()->nonEmptyString('userId'));
		} else {
			$update->update('spreedme_room_participants')
				->set('participanttype', $update->createNamedParameter(Participant::MODERATOR))
				->where($update->expr()->nonEmptyString('userId'));
		}

		if (!empty($one2oneRooms)) {
			$update->andWhere($update->expr()->notIn('roomId', $update->createNamedParameter($one2oneRooms, IQueryBuilder::PARAM_INT_ARRAY)));
		}

		return $update->execute();
	}
}
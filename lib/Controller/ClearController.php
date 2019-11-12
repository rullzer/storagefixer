<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2019, Roeland Jago Douma <roeland@famdouma.nl>
 *
 * @author Roeland Jago Douma <roeland@famdouma.nl>
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

namespace OCA\StorageFixer\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IRequest;

class ClearController extends Controller {


	/** @var IDBConnection */
	private $db;
	/** @var IRootFolder */
	private $rootFolder;

	public function __construct($appName,
				    IRequest $request, IDBConnection $db, IRootFolder $rootFolder) {
		parent::__construct($appName, $request);
		$this->db = $db;
		$this->rootFolder = $rootFolder;
	}

	/**
	 * @NoCSRFRequired
	 *
	 * @param string $uid The UID to cleanup
	 * @return JSONResponse
	 */
	public function clear(string $uid): JSONResponse {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('storages')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter('home::'.$uid))
			);

		$cursor = $qb->execute();
		$data = $cursor->fetchAll();
		if (count($data) === 0) {
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$cursor->closeCursor();

		// First clean the user files
		$userFolder = $this->rootFolder->getUserFolder($uid);
		$this->cleanupFolder($userFolder);

		// Now clean the user root
		$userRoot = $userFolder->getParent();
		$this->cleanupFolder($userRoot);

		//Now remove all user preferences
		$this->cleanupSettings($uid);

		//Now just clean all remaining entries on the user storage and the storage itself
		$this->cleanupStorage((int)$data[0]['numeric_id']);

		return new JSONResponse([]);
	}

	/**
	 * Do a full iteration over all the files/folders in a folder and delete
	 * it all.
	 *
	 * Do this a bit more expressively since that makes sure the objectstore and db also get cleaned
	 */
	private function cleanupFolder(Folder $folder) {
		$nodes = $folder->getDirectoryListing();

		foreach ($nodes as $node) {
			if ($node instanceof File) {
				try {
					$node->delete();
				} catch (NotPermittedException $e) {
					// Just continue
				}
			} else if ($node instanceof Folder) {
				$this->cleanupFolder($node);
				try {
					$node->delete();
				} catch (NotPermittedException $e) {
					// Just continue
				}
			}
		}
	}

	private function cleanupStorage(int $id) {
		// Delete remaining leftovers from filecache
		$qb = $this->db->getQueryBuilder();
		$qb->delete('filecache')
			->where(
				$qb->expr()->eq('storage', $qb->createNamedParameter($id))
			);
		$qb->execute();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('storages')
			->where(
				$qb->expr()->eq('numeric_id', $qb->createNamedParameter($id))
			);

		$qb->execute();
	}

	private function cleanupSettings(string $uid) {
		$qb = $this->db->getQueryBuilder();

		$qb->delete('preferences')
			->where(
				$qb->expr()->eq('userid', $qb->createNamedParameter($uid))
			);

		$qb->execute();
	}

}

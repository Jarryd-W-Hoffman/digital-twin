# Organisations and members

Everything in Meridian belongs to an **organisation**: its projects, twins, uploads, devices and its library of custom types. People join an organisation with a **role**, which decides what they can do in it. One person can belong to several organisations, with a different role in each.

![The Members page: everyone in Northshore Logistics with their role, and the form to add a member.](../images/members.png)

## Roles

|                                                                                 | Owner | Admin | Operator | Viewer |
| ------------------------------------------------------------------------------- | :---: | :---: | :------: | :----: |
| See projects and twins, open them, and watch them live                          |   ✓   |   ✓   |    ✓     |   ✓    |
| Raise issues in a twin and comment on them                                      |   ✓   |   ✓   |    ✓     |   ✓    |
| Save views of a twin and copy links to them                                     |   ✓   |   ✓   |    ✓     |   ✓    |
| Send commands to a twin (setpoints, taking equipment out of service, scenarios) |   ✓   |   ✓   |    ✓     |        |
| Close anyone's issue                                                            |   ✓   |   ✓   |    ✓     |        |
| Create projects and twins, upload, place, save and restore versions             |   ✓   |   ✓   |          |        |
| Share views, and choose the view a twin opens at                                |   ✓   |   ✓   |          |        |
| Issue gateway tokens, add devices, map telemetry                                |   ✓   |   ✓   |          |        |
| Import, save and delete types in the type library                               |   ✓   |   ✓   |          |        |
| Add members and change roles                                                    |   ✓   |   ✓   |          |        |

- **Viewers** see everything but change nothing: the inspector's controls are disabled for them, and the twin ignores their commands.
- An organisation always keeps **at least one owner**. Demoting or removing the last owner is refused.
- Everything is private to the organisation: uploaded files are served only to its members, and a gateway's browsers need a pass signed for that user.

## Switch organisations

Choose the organisation's name at the top of the sidebar. The menu lists every organisation you belong to, with a tick beside the current one. Choose another to switch; the pages you see change to its projects and twins.

## Start a new organisation

1. Choose the organisation's name at the top of the sidebar, then **New organisation**.
2. Enter a **Name** and choose **Create**.

You're its owner, and Meridian switches to it straight away. It starts empty: [create a project](03-projects-and-the-library.md#create-a-project) next.

## Add a member

The person needs a Meridian account first: send them the sign-up link, or have them choose **Sign up** on the sign-in page.

1. Choose **Members** in the sidebar.
2. Under **Add a member**, enter their **Email** and choose a **Role**.
3. Choose **Add**.

They see the organisation in their switcher the next time they load a page.

## Change a role or remove someone

On **Members**, owners and admins see a role menu beside each person:

- **Change a role:** choose the new one from the menu. It applies at once.
- **Remove someone:** choose **Remove** at the end of their row and confirm. They lose access to everything in the organisation; nothing they made is deleted.

## The type library

A [custom type](12-twin-definitions.md#custom-asset-types) describes equipment Meridian has no built-in physics for: a fire water tank, a pump, a meter. The **type library** keeps an organisation's types in one place, so each is written once and used in any twin. Choose **Types** in the sidebar to see it.

![The type library: each type with its colour, metrics and states, what it reads, who saved it, and the import form.](../images/types.png)

Each type shows its metrics, how many states it has, and who saved it last. **Reads fire-tank** means the type's expressions read an asset called `fire-tank`, so a twin needs an asset with that id to use it. Everyone can **Download** a type as JSON.

### Import types

1. On **Types**, under **Import types**, choose a JSON file. It can hold one type, a list of them, or a whole twin definition, whose types are taken from it.
2. Choose **Import**.

Every type is checked the way a twin checks it. If any has a problem, nothing is imported and every problem is listed. A type with the same id as one already in the library replaces it.

### Use a type in a twin

In the twin's **Layout** panel, the **Types** section lists the twin's own types and the library's other ones. See [Layout and versions](08-layout-and-versions.md#types). Both ways are copies: changing or deleting a library type never changes a twin that uses it, until that twin takes the new version.

**Delete** takes a type out of the library. Twins that use it keep their copy.

## Tips

- **Give people the smallest role that works.** A control room that only needs to watch and respond to alerts can be operators; people who only need to look can be viewers.
- **Keep shared equipment in the library.** Save a type from the twin where you got it right, and other twins can take it from there.
- **Use separate organisations for separate clients.** Nothing is shared between organisations, so a consultant can keep each client's sites apart and switch between them.

## See also

- [Projects and the library](03-projects-and-the-library.md): what editors can upload.
- [Your account](13-your-account.md): two-factor authentication and passkeys.

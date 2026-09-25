using UnrealBuildTool;

public class SpikiaSignAvatar : ModuleRules
{
	public SpikiaSignAvatar(ReadOnlyTargetRules Target) : base(Target)
	{
		PCHUsage = PCHUsageMode.UseExplicitOrSharedPCHs;

		PublicDependencyModuleNames.AddRange(new string[]
		{
			"Core",
			"CoreUObject",
			// UAnimInstance / PlaySlotAnimationAsDynamicMontage / USkeletalMeshComponent ya
			// viven en el modulo "Engine" - no hace falta AnimGraphRuntime (eso es para
			// nodos custom de AnimGraph, no para disparar montages desde C++).
			"Engine",
			// IWebSocketModule / IWebSocket: cliente WebSocket nativo de Unreal, usado para
			// mantener la conexion persistente con el orquestador de Node.js.
			"WebSockets",
			// FJsonSerializer / TJsonReader / FJsonObject: parseo del payload JSON que manda
			// el orquestador ({"type":"sign_frame","gloss":"HOLA",...}).
			"Json",
			"JsonUtilities",
		});

		PrivateDependencyModuleNames.AddRange(new string[]
		{
			"Slate",
			"SlateCore",
		});
	}
}
